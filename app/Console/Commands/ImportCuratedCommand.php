<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportPokemonSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Imports the curated set list in one command — for seeding a fresh environment
 * (e.g. production). Idempotent: re-runs upsert items + (re)compute values.
 * Use --no-images for a fast "revalue" pass when images already exist on S3.
 */
class ImportCuratedCommand extends Command
{
    protected $signature = 'catalog:import-curated
        {--no-prices : skip valuation seeding}
        {--no-images : skip card images (safe re-run preserves existing)}';

    protected $description = 'Import the curated Pokémon set list from pokemontcg.io';

    /** The sets we ship — keep in sync with the live catalog. */
    private const SETS = [
        'sv3pt5',    // 151
        'sv4pt5',    // Paldean Fates
        'sv5',       // Temporal Forces
        'sv6',       // Twilight Masquerade
        'sv6pt5',    // Shrouded Fable
        'sv7',       // Stellar Crown
        'sv8',       // Surging Sparks
        'sv8pt5',    // Prismatic Evolutions
        'me4',       // Chaos Rising
        'zsv10pt5',  // Black Bolt
        'rsv10pt5',  // White Flare
    ];

    public function handle(ImportPokemonSet $import): int
    {
        @ini_set('memory_limit', '1024M');

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');
        $items = 0;
        $valued = 0;

        foreach (self::SETS as $id) {
            $this->line("Importing <info>{$id}</info>…");

            try {
                $r = $import($id, $withPrices, $withImages);
                $items += $r['items'];
                $valued += $r['valued'];
                $this->line("  {$r['set']}: {$r['items']} items, {$r['valued']} valued, {$r['images']} images");
            } catch (Throwable $e) {
                $this->error("  {$id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Done — {$items} items, {$valued} valued across ".count(self::SETS).' sets.');

        return self::SUCCESS;
    }
}
