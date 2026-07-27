<?php

namespace App\Console\Commands;

use App\Actions\Valuation\SweepEbaySold;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Run the configured broad eBay sold-listing sweeps. Each search fires only when
 * its own interval has elapsed (cache cooldown), so a frequent scheduler tick
 * staggers the paid Oxylabs calls under the shared daily cap. Matched sales feed
 * the normal valuation pipeline; unplaceable ones land in ebay_sweep_misses.
 */
class SweepEbaySoldCommand extends Command
{
    protected $signature = 'valuation:sweep-ebay
        {--label= : run only this search label}
        {--force : ignore each search\'s interval cooldown}
        {--dry-run : fetch and match but write nothing}';

    protected $description = 'Sweep eBay sold listings and apply matched sales to card values';

    public function handle(SweepEbaySold $sweep, OxylabsClient $oxylabs): int
    {
        if (! config('valuation.ebay.enabled') || ! config('valuation.ebay.sweep.enabled')) {
            $this->warn('eBay sweep is disabled.');

            return self::SUCCESS;
        }

        $only = $this->option('label');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force') || $dryRun;

        foreach ((array) config('valuation.ebay.sweep.searches', []) as $search) {
            if ($only && ($search['label'] ?? null) !== $only) {
                continue;
            }

            if (! $force && Cache::has($this->cooldownKey($search['label']))) {
                continue; // not due yet
            }

            // Advisory: OxylabsClient bills and enforces per delivered result.
            if (! $dryRun && ! $oxylabs->hasBudget(OxylabsClient::BUDGET_EBAY)) {
                $this->warn('eBay daily request cap reached; stopping.');
                break;
            }

            try {
                $r = $sweep($search, $dryRun);
            } catch (Throwable $e) {
                $this->error("{$search['label']} failed: {$e->getMessage()}");

                continue;
            }

            if (! $dryRun) {
                Cache::put(
                    $this->cooldownKey($search['label']),
                    true,
                    Carbon::now()->addMinutes((int) ($search['interval_minutes'] ?? 30)),
                );
            }

            $this->line("{$r['label']}: fetched {$r['fetched']}, matched {$r['matched']}, ".
                ($dryRun ? 'would store' : 'stored')." {$r['stored']}, missed {$r['missed']}, recomputed {$r['recomputed']}");
        }

        return self::SUCCESS;
    }

    private function cooldownKey(string $label): string
    {
        return "ebay:sweep:cooldown:{$label}";
    }
}
