<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportPokemonSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import one or more real Pokémon sets from pokemontcg.io into the catalog,
 * with estimated valuations + card images. Re-runnable (idempotent upserts).
 */
class ImportSetCommand extends Command
{
    protected $signature = 'catalog:import-set
        {ids* : pokemontcg.io set ids (e.g. sv8 sv8pt5)}
        {--no-prices : skip valuation seeding}
        {--no-images : skip downloading card images}';

    protected $description = 'Import real Pokémon set(s) from pokemontcg.io';

    public function handle(ImportPokemonSet $import): int
    {
        // Importing a full set fetches/uploads hundreds of images + seeds comps.
        @ini_set('memory_limit', '1024M');

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');

        foreach ($this->argument('ids') as $id) {
            $this->line("Importing <info>{$id}</info>…");

            try {
                $r = $import($id, $withPrices, $withImages);
                $this->line("  {$r['set']}: {$r['items']} items, {$r['valued']} valued, {$r['images']} images");
            } catch (Throwable $e) {
                $this->error("  {$id} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
