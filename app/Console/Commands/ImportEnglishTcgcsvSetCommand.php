<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportEnglishTcgcsvSet;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import an English Pokémon set from TCGCSV (TCGplayer category 3) by group id —
 * for new sets pokemontcg.io hasn't published yet. Cards only; sealed products
 * come via `catalog:import-sealed`. Idempotent; a later `catalog:import-set`
 * refines the same rows once pokemontcg.io catches up.
 */
class ImportEnglishTcgcsvSetCommand extends Command
{
    protected $signature = 'catalog:import-en-tcgcsv
        {groupIds* : TCGplayer English group ids (e.g. 24688 for "ME05: Pitch Black")}
        {--no-prices : skip valuation seeding}
        {--no-images : skip downloading card images}';

    protected $description = 'Import English Pokémon set(s) from TCGCSV (for sets pokemontcg.io lacks)';

    public function handle(ImportEnglishTcgcsvSet $import): int
    {
        @ini_set('memory_limit', '1024M');

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');

        foreach ($this->argument('groupIds') as $id) {
            $this->line("Importing EN <info>{$id}</info>…");

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
