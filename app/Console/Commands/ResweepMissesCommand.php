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

        // Each configured search declares its language; map label => language so a
        // re-resolve filters to the right catalog language (avoids cross-language ties).
        $langByLabel = collect(config('valuation.ebay.sweep.searches', []))
            ->keyBy('label')->map(fn ($s) => $s['language'] ?? null);

        $counts = ['applied' => 0, 'reclassified' => 0, 'rematched' => 0, 'unchanged' => 0, 'skipped' => 0];

        EbaySweepMiss::query()
            ->when($this->option('label'), fn (Builder $q, $l) => $q->where('search_label', $l))
            ->when($this->option('reason'), fn (Builder $q, $r) => $q->where('reason', $r))
            ->chunkById(200, function ($misses) use ($sweep, $langByLabel, $minScore, $companyIds, $apply, &$counts) {
                foreach ($misses as $miss) {
                    $language = $langByLabel[$miss->search_label] ?? null;
                    $outcome = $sweep->reprocessMiss($miss, $language, $minScore, $companyIds, $apply);
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }
            });

        $this->table(
            ['outcome', 'count'],
            collect($counts)->map(fn ($c, $k) => [$k, $c])->values()->all(),
        );

        $verb = $apply ? 'applied' : 'would apply';
        $this->info("{$verb} {$counts['applied']} sale(s); refreshed ".
            ($counts['reclassified'] + $counts['rematched']).' miss(es).'.
            ($apply ? '' : ' (dry run — nothing written)'));

        return self::SUCCESS;
    }
}
