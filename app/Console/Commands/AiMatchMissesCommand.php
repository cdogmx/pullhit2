<?php

namespace App\Console\Commands;

use App\Actions\Valuation\SweepEbaySold;
use App\Models\EbaySweepMiss;
use App\Models\EbaySweepOverride;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\CardTextExtractor;
use App\Support\Scanning\IdentifiedCard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * AI pass over the eBay sweep misses the deterministic resolver couldn't place.
 * Reads each listing TITLE (cheap, batched many-per-call) into structured card
 * identity, matches it to the catalog with the scanner's matcher, then:
 *   - auto-applies the sale when the read AND the match are both confident, else
 *   - records the catalog card as the miss's best-guess for one-click review.
 * Priced state (grade/condition) still comes from the shared classifier, so the
 * ingested sale stays consistent with the sweep and per-card paths.
 */
class AiMatchMissesCommand extends Command
{
    protected $signature = 'valuation:ai-match-misses
        {--limit=200 : max misses to process (cost guard)}
        {--batch=25 : titles per AI call}
        {--label= : only this search label}
        {--reason= : only misses with this reason}
        {--min-confidence=0.7 : AI identity confidence to auto-apply}
        {--min-score=0.8 : catalog match score to auto-apply}
        {--suggest-only : never auto-apply; only record best-guesses}
        {--dry-run : report outcomes, write nothing}';

    protected $description = 'Use AI to match unplaceable eBay sweep misses to catalog cards (from the title text)';

    public function handle(CardTextExtractor $extractor, CandidateMatcher $matcher, SweepEbaySold $sweep): int
    {
        $apply = ! $this->option('dry-run') && ! $this->option('suggest-only');
        $minConfidence = (float) $this->option('min-confidence');
        $minScore = (float) $this->option('min-score');
        $batchSize = max(1, (int) $this->option('batch'));

        $misses = EbaySweepMiss::query()
            ->when($this->option('label'), fn (Builder $q, $l) => $q->where('search_label', $l))
            ->when($this->option('reason'), fn (Builder $q, $r) => $q->where('reason', $r))
            ->whereNotNull('price')->where('price', '>', 0)
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($misses->isEmpty()) {
            $this->info('No misses to process.');

            return self::SUCCESS;
        }

        // Skip listings an admin already rejected.
        $rejected = EbaySweepOverride::where('action', EbaySweepOverride::REJECT)
            ->whereIn('source_listing_id', $misses->pluck('source_listing_id')->filter())
            ->pluck('source_listing_id')->flip();

        $counts = ['applied' => 0, 'suggested' => 0, 'no_match' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($misses->chunk($batchSize) as $chunk) {
            $batch = $chunk->values();

            try {
                $reads = $extractor->extract($batch->pluck('title')->all());
            } catch (Throwable $e) {
                $this->error('AI batch failed: '.$e->getMessage());
                $counts['errors'] += $batch->count();

                continue;
            }

            foreach ($batch as $i => $miss) {
                if ($rejected->has($miss->source_listing_id)) {
                    $counts['skipped']++;

                    continue;
                }

                $fields = $reads[$i] ?? null;
                if (! $fields || empty($fields['name'])) {
                    $counts['no_match']++;

                    continue;
                }

                $matches = $matcher->match(IdentifiedCard::fromVision($fields));
                $top = $matches[0] ?? null;
                if (! $top) {
                    $counts['no_match']++;

                    continue;
                }

                $confidence = (float) ($fields['confidence'] ?? 0);
                $card = $top['item'];

                if ($apply && $confidence >= $minConfidence && $top['score'] >= $minScore) {
                    $sweep->applyMissToCard($miss, $card, 'ai-'.$miss->search_label);
                    $counts['applied']++;
                } elseif (! $this->option('dry-run')) {
                    $sweep->suggestMissCard($miss, $card, $top['score']);
                    $counts['suggested']++;
                } else {
                    $counts[$confidence >= $minConfidence && $top['score'] >= $minScore ? 'applied' : 'suggested']++;
                }
            }
        }

        $this->table(
            ['outcome', 'count'],
            collect($counts)->map(fn ($c, $k) => [$k, $c])->values()->all(),
        );

        $note = $this->option('dry-run') ? ' (dry run — nothing written)' : '';
        $this->info("processed {$misses->count()} misses: {$counts['applied']} applied, {$counts['suggested']} suggested.{$note}");

        return self::SUCCESS;
    }
}
