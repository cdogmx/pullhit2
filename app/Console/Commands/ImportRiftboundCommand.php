<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportRiftbound;
use Illuminate\Console\Command;

/**
 * Import (or re-sync) the Riftbound catalog from Riot's public card-gallery feed.
 */
class ImportRiftboundCommand extends Command
{
    protected $signature = 'catalog:import-riftbound
        {--dry-run : fetch + parse and report, write nothing}
        {--no-images : skip downloading card images}
        {--limit= : only the first N cards (testing)}';

    protected $description = 'Import the Riftbound cards from Riot\'s card-gallery feed into the catalog';

    public function handle(ImportRiftbound $import): int
    {
        @ini_set('memory_limit', '1024M');

        $this->info('Fetching cards from Riot\'s publishing-content feed…');

        $result = $import(
            dryRun: (bool) $this->option('dry-run'),
            withImages: ! $this->option('no-images'),
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
        );

        if ($result['cards'] === 0) {
            $this->error('The feed returned no cards — nothing imported.');

            return self::FAILURE;
        }

        $this->line("Cards: {$result['cards']} · Images: {$result['images']}");

        foreach ($result['sets'] as $set => $n) {
            $this->line(sprintf('  %-20s %d', $set, $n));
        }

        foreach ($result['sample'] as $c) {
            $this->line(sprintf('  %-10s %-28s %-14s %-11s %s',
                $c['number'] ?? '?', $c['name'] ?? '?', $c['set'] ?? '?', $c['rarity'] ?? '-', $c['type'] ?? '-'));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }
}
