<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use App\Models\SaleObservation;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\CardTextExtractor;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Valuation\PriceDivergence;
use Illuminate\Console\Command;
use Throwable;

/**
 * AI pass over the comps of cards whose price disagrees with PriceCharting.
 *
 * The complement to valuation:ai-match-misses, which rescues listings the
 * resolver could not place. This one re-reads listings it DID place, on the
 * small set of cards where the result looks wrong.
 *
 * Why not every comp: the deterministic classifier is right on about 99.8% of
 * them, and its failures have been rule-shaped — a grader brand missing from a
 * list, "graded" missing from a filler list, "NO. 250" unrecognised. Each of
 * those is fixed once, for free, and pinned by a test; a model would flag
 * instances of them forever at recurring cost. Prices also need to be
 * reproducible, and a model that judges the same title differently on two runs
 * makes them wobble with no audit trail.
 *
 * Where a model genuinely wins is the judgement a regex cannot make: "Umbreon
 * Gold Star 17/17 Celebrations Classic Collection" is a $120 reprint pricing the
 * $4,700 POP Series 5 original, and the string rule written for it produced 96%
 * false positives because our own promo set names are near-duplicates.
 *
 * The AI only READS a title into fields. Matching those fields to a card stays
 * deterministic, so every verdict can be explained and replayed — the model is
 * never asked "should this be deleted".
 *
 * Suggest-only by default. Its best output is not a deletion but a pattern worth
 * turning into a classifier rule.
 */
class AdjudicateCompsCommand extends Command
{
    protected $signature = 'valuation:adjudicate-comps
        {--ratio=3 : judge cards at or beyond this multiple from the reference}
        {--min-cents=300 : ignore cards cheaper than this}
        {--set= : only cards in this set, by slug}
        {--cards=40 : maximum cards to judge (cost guard)}
        {--batch=25 : titles per AI call}
        {--min-confidence=0.7 : AI read confidence before a verdict counts}
        {--min-score=0.8 : catalog match score before a verdict counts}
        {--apply : delete the comps judged to belong to another card}';

    protected $description = 'Use AI to re-judge the comps of cards whose price disagrees with PriceCharting';

    public function handle(
        PriceDivergence $divergence,
        CardTextExtractor $extractor,
        CandidateMatcher $matcher,
        RecomputeCatalogItem $recompute,
    ): int {
        $apply = (bool) $this->option('apply');
        $minConfidence = (float) $this->option('min-confidence');
        $minScore = (float) $this->option('min-score');
        $batchSize = max(1, (int) $this->option('batch'));

        $suspect = $divergence
            ->cards((float) $this->option('ratio'), (int) $this->option('min-cents'), $this->option('set'))
            ->take((int) $this->option('cards'));

        if ($suspect->isEmpty()) {
            $this->info('No divergent cards to judge.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Judging the comps of %s divergent card(s)…', number_format($suspect->count())));

        $counts = ['checked' => 0, 'belongs_elsewhere' => 0, 'confirmed' => 0, 'unreadable' => 0, 'errors' => 0];
        $findings = [];
        $touched = [];

        foreach ($suspect as $row) {
            /** @var CatalogItem $card */
            $card = $row['item'];

            $comps = SaleObservation::where('catalog_item_id', $card->id)
                ->whereNull('grading_company_id')
                ->where('is_synthetic', false)
                ->whereNotNull('raw->title')
                ->get();

            foreach ($comps->chunk($batchSize) as $chunk) {
                $batch = $chunk->values();

                try {
                    $reads = $extractor->extract($batch->map(fn ($o) => (string) ($o->raw['title'] ?? ''))->all());
                } catch (Throwable $e) {
                    $this->error('AI batch failed: '.$e->getMessage());
                    $counts['errors'] += $batch->count();

                    continue;
                }

                foreach ($batch as $i => $observation) {
                    $counts['checked']++;
                    $fields = $reads[$i] ?? null;

                    if (! $fields || empty($fields['name'])) {
                        $counts['unreadable']++;

                        continue;
                    }

                    $top = $matcher->match(IdentifiedCard::fromVision($fields))[0] ?? null;

                    // No confident read or no confident match is not evidence of
                    // anything. Saying nothing is the correct answer.
                    if (! $top
                        || (float) ($fields['confidence'] ?? 0) < $minConfidence
                        || $top['score'] < $minScore) {
                        $counts['unreadable']++;

                        continue;
                    }

                    if ($top['item']->id === $card->id) {
                        $counts['confirmed']++;

                        continue;
                    }

                    $counts['belongs_elsewhere']++;
                    $touched[$card->id] = $card;

                    $findings[] = [
                        sprintf('%.1fx', $row['ratio']),
                        '$'.number_format((float) $observation->price / 100, 2),
                        mb_substr($card->name, 0, 20).' #'.$card->number,
                        mb_substr($top['item']->name, 0, 20).' #'.$top['item']->number,
                        mb_substr((string) ($observation->raw['title'] ?? ''), 0, 44),
                    ];

                    if ($apply) {
                        $observation->delete();
                    }
                }
            }
        }

        if ($apply && $touched !== []) {
            foreach ($touched as $card) {
                ($recompute)($card);
            }
        }

        if ($findings !== []) {
            $this->newLine();
            $this->table(['card ratio', 'price', 'filed under', 'reads as', 'title'], array_slice($findings, 0, 40));

            if (count($findings) > 40) {
                $this->line(sprintf('  … and %s more', number_format(count($findings) - 40)));
            }
        }

        $this->newLine();
        $this->table(['outcome', 'count'], collect($counts)->map(fn ($c, $k) => [$k, number_format($c)])->values()->all());

        $verb = $apply ? 'removed' : 'would remove';
        $this->info(sprintf(
            '%s %s comp(s) across %s card(s).%s',
            $verb,
            number_format($counts['belongs_elsewhere']),
            number_format(count($touched)),
            $apply ? ' Recomputed.' : ' Re-run with --apply to act on it.',
        ));

        // The findings are worth more as a pattern than as deletions. A repeated
        // shape here — a number form the gate cannot read, a reprint naming its
        // own set — belongs in the classifier, where it costs nothing and is
        // covered by a test.
        if (! $apply && $counts['belongs_elsewhere'] > 0) {
            $this->line('Look for a shape in the titles above before applying: a repeated one belongs in the classifier, not in a nightly delete.');
        }

        return self::SUCCESS;
    }
}
