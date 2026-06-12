<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportJapaneseSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import Japanese Pokémon set(s) from TCGCSV (TCGplayer Japan, category 85) by
 * group id, with estimated values + images. Re-runnable (idempotent upserts).
 */
class ImportJapaneseSetCommand extends Command
{
    protected $signature = 'catalog:import-jp
        {groupIds* : TCGplayer Japan group ids (e.g. 24600 for "M3: Nihil Zero")}
        {--no-prices : skip valuation seeding}
        {--no-images : skip downloading card images}';

    protected $description = 'Import Japanese Pokémon set(s) from TCGCSV';

    public function handle(ImportJapaneseSet $import): int
    {
        @ini_set('memory_limit', '1024M');

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');

        foreach ($this->argument('groupIds') as $id) {
            $this->line("Importing JP <info>{$id}</info>…");

            try {
                $r = $import((int) $id, $withPrices, $withImages);
                $this->line("  {$r['set']}: {$r['items']} items, {$r['valued']} valued, {$r['images']} images");
            } catch (Throwable $e) {
                $this->error("  {$id} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
