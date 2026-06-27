<?php

namespace App\Console\Commands;

use App\Actions\Valuation\SweepSealedComps;
use Illuminate\Console\Command;

/**
 * Warm real eBay sold comps for valuable, stale sealed products (the broad
 * number-based sweep skips sealed). Cost-capped via the shared Oxylabs budget.
 */
class SweepSealedCompsCommand extends Command
{
    protected $signature = 'valuation:sweep-sealed
        {--limit=40 : max sealed products this run}
        {--min-value=2000 : skip sealed valued below this (cents) — not worth a paid pull}
        {--max-value=500000 : skip sealed valued above this (cents) — data-error / ultra-thin outliers}
        {--max-age=168 : only products not refreshed within this many hours}
        {--dry-run : list what would be pulled, fetch nothing}';

    protected $description = 'Pull eBay sold comps for valuable sealed products by name (cost-capped)';

    public function handle(SweepSealedComps $sweep): int
    {
        if (! config('valuation.ebay.enabled')) {
            $this->warn('eBay valuation is disabled.');

            return self::SUCCESS;
        }

        $r = $sweep(
            limit: (int) $this->option('limit'),
            minValueCents: (int) $this->option('min-value'),
            maxValueCents: (int) $this->option('max-value'),
            maxAgeHours: (int) $this->option('max-age'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $verb = $this->option('dry-run') ? 'would pull' : 'pulled';
        $this->line("due: {$r['due']} · {$verb} {$r['processed']} · comps ingested: {$r['ingested']}"
            .($r['capped'] ? ' · stopped at daily cap' : ''));

        return self::SUCCESS;
    }
}
