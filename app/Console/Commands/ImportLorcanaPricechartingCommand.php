<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportLorcanaPricecharting;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seed Lorcana valuations from PriceCharting and create the sets lorcana-api.com
 * doesn't carry yet (Wilds Unknown, Promo, Illumineer's Quest). Re-runnable.
 */
class ImportLorcanaPricechartingCommand extends Command
{
    protected $signature = 'catalog:import-lorcana-pricecharting
        {--no-create : only price existing sets; do not create sets lorcana-api lacks}';

    protected $description = 'Seed Lorcana prices from PriceCharting + import its missing sets';

    public function handle(ImportLorcanaPricecharting $import): int
    {
        @ini_set('memory_limit', '1024M');

        $createMissing = ! $this->option('no-create');
        $this->line('Fetching PriceCharting lorcana-cards guide…');

        try {
            $result = $import($createMissing);
        } catch (Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($result['sets'] as $s) {
            $created = $s['created'] > 0 ? ", {$s['created']} created" : '';
            $this->line("  [{$s['status']}] {$s['set']}: {$s['priced']} priced{$created}");
        }

        return self::SUCCESS;
    }
}
