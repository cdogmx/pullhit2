<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportLorcana;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import Disney Lorcana cards from lorcana-api.com into the catalog, with card
 * images stored in our bucket. Re-runnable (idempotent upserts). Valuations are
 * not seeded here — they come from eBay sold comps (valuation:refresh-ebay).
 */
class ImportLorcanaCommand extends Command
{
    protected $signature = 'catalog:import-lorcana
        {sets?* : Set codes to import (e.g. ARI INK); omit for all sets}
        {--no-images : skip downloading card images}';

    protected $description = 'Import Disney Lorcana card(s) from lorcana-api.com';

    public function handle(ImportLorcana $import): int
    {
        // The bulk feed is one large response; importing uploads many images.
        @ini_set('memory_limit', '1024M');

        $setIds = (array) $this->argument('sets');
        $withImages = ! $this->option('no-images');

        $this->line($setIds === []
            ? 'Importing <info>all</info> Lorcana sets…'
            : 'Importing Lorcana sets <info>'.implode(', ', $setIds).'</info>…');

        try {
            $result = $import($setIds, $withImages);
        } catch (Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result['sets'] === []) {
            $this->warn('No matching sets found in the feed.');

            return self::SUCCESS;
        }

        foreach ($result['sets'] as $s) {
            $this->line("  {$s['set']}: {$s['items']} items, {$s['images']} images");
        }

        return self::SUCCESS;
    }
}
