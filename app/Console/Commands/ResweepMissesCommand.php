<?php

namespace App\Console\Commands;

use App\Actions\Valuation\SweepEbaySold;
use App\Models\EbaySweepMiss;
use App\Models\GradingCompany;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-run the resolver/classifier over already-logged sweep misses — no eBay
 * fetch, so it costs nothing. Use it after improving title parsing to recover
 * sales the old resolver couldn't place: matches are ingested and their miss
 * cleared, the rest have their reason / best-guess refreshed in place.
 */
class ResweepMissesCommand extends Command
{
    protected $signature = 'valuation:resweep-misses
        {--label= : only misses from this search label (e.g. lorcana-psa10)}
        {--reason= : only misses with this reason (e.g. no_number)}
        {--dry-run : report what would happen, write nothing}';

    protected $description = 'Re-evaluate logged eBay sweep misses against the current resolver (no network)';

    public function handle(SweepEbaySold $sweep): int
    {
        $apply = ! $this->option('dry-run');
        $minScore = (float) config('valuation.ebay.sweep.min_score', 0.75);
        $companyIds = GradingCompany::pluck('id', 'slug')->all();

        // Each configured search declares its language and game; map label => both
        // so a re-resolve filters to the right catalog language AND product line
        // (avoids cross-language ties and cross-game number collisions).
        $searches = collect(config('valuation.ebay.sweep.searches', []))->keyBy('label');
        $langByLabel = $searches->map(fn ($s) => $s['language'] ?? null);
        $lineByLabel = $searches->map(fn ($s) => $s['line'] ?? null);

        $counts = ['applied' => 0, 'reclassified' => 0, 'rematched' => 0, 'unchanged' => 0, 'skipped' => 0];

        // Two batch wins over a corpus this size: the override table is a few
        // hundred rows against tens of thousands of misses, so load it once
        // instead of per row; and thousands of recovered sales land on far fewer
        // cards, so derive each card's value once at the end.
        $sweep->primeOverrides();
        $sweep->deferRecomputes();

        $query = EbaySweepMiss::query()
            ->when($this->option('label'), fn (Builder $q, $l) => $q->where('search_label', $l))
            ->when($this->option('reason'), fn (Builder $q, $r) => $q->where('reason', $r));

        $total = (clone $query)->count();
        $this->info("Re-evaluating {$total} miss(es)".($apply ? '' : ' (dry run)').'…');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(200, function ($misses) use ($sweep, $langByLabel, $lineByLabel, $minScore, $companyIds, $apply, &$counts, $bar) {
            foreach ($misses as $miss) {
                $language = $langByLabel[$miss->search_label] ?? null;
                $line = $lineByLabel[$miss->search_label] ?? null;
                $outcome = $sweep->reprocessMiss($miss, $language, $minScore, $companyIds, $apply, $line);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $cards = $sweep->pendingRecomputes();
        $recomputed = 0;

        if ($apply && $cards > 0) {
            $this->info("Recomputing {$cards} affected card(s)…");
            $valueBar = $this->output->createProgressBar($cards);
            $valueBar->start();
            $recomputed = $sweep->flushRecomputes(fn () => $valueBar->advance());
            $valueBar->finish();
            $this->newLine(2);
        }

        $this->table(
            ['outcome', 'count'],
            collect($counts)->map(fn ($c, $k) => [$k, $c])->values()->all(),
        );

        $verb = $apply ? 'applied' : 'would apply';
        $this->info("{$verb} {$counts['applied']} sale(s); refreshed ".
            ($counts['reclassified'] + $counts['rematched']).' miss(es)'.
            ($apply ? "; recomputed {$recomputed} card(s)." : '. (dry run — nothing written)'));

        return self::SUCCESS;
    }
}
