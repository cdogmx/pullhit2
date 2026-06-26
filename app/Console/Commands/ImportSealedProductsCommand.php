<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportSealedProducts;
use App\Models\Set;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import a set's sealed products (booster boxes, ETBs, packs, …) from TCGCSV.
 */
class ImportSealedProductsCommand extends Command
{
    protected $signature = 'catalog:import-sealed
        {setId : our catalog Set id}
        {groupId : TCGplayer group id for the set (e.g. 24655 for Chaos Rising)}
        {--category=3 : TCGplayer category id (3 = English Pokémon, 85 = Japanese)}
        {--no-images : skip downloading product images}
        {--dry-run : list the sealed products without writing}';

    protected $description = 'Import a set\'s sealed products (boxes, ETBs, packs) + images from TCGCSV';

    public function handle(ImportSealedProducts $import): int
    {
        @ini_set('memory_limit', '1024M');

        $set = Set::find((int) $this->argument('setId'));
        if (! $set) {
            $this->error("Set {$this->argument('setId')} not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line("Sealed import for <info>{$set->name}</info> from TCGplayer group <info>{$this->argument('groupId')}</info>".($dryRun ? ' (dry run)' : '').'…');

        try {
            $r = $import(
                set: $set,
                groupId: (int) $this->argument('groupId'),
                category: (int) $this->option('category'),
                dryRun: $dryRun,
                withImages: ! $this->option('no-images'),
            );
        } catch (Throwable $e) {
            $this->error("  failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($r['sample'] as $s) {
            $price = $s['price'] !== null ? '$'.number_format($s['price'] / 100, 2) : '—';
            $this->line(sprintf('  %-22s %-20s %s %s', $s['type'], $price, $s['has_image'] ? '🖼' : '  ', $s['name']));
        }

        $verb = $dryRun ? 'would import' : 'imported';
        $this->line("  {$verb}: {$r['created']} sealed · images: {$r['images']} · valued: {$r['valued']}");

        if ($r['skipped'] !== []) {
            $this->comment('  skipped (not sealed): '.implode(', ', array_slice($r['skipped'], 0, 10)).(count($r['skipped']) > 10 ? '…' : ''));
        }

        return self::SUCCESS;
    }
}
