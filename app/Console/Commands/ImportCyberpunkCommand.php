<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportCyberpunk;
use Illuminate\Console\Command;

/**
 * Import (or re-sync) the Cyberpunk TCG catalog from cyberpunktcg.com.
 */
class ImportCyberpunkCommand extends Command
{
    protected $signature = 'catalog:import-cyberpunk
        {--dry-run : scrape + parse and report, write nothing}
        {--no-images : skip downloading card images}
        {--limit= : only the first N cards (testing)}';

    protected $description = 'Import the Cyberpunk TCG cards from the Netdeck.gg API into the catalog';

    public function handle(ImportCyberpunk $import): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info('Fetching cards from the Netdeck.gg API…');
        $result = $import(
            dryRun: (bool) $this->option('dry-run'),
            withImages: ! $this->option('no-images'),
            limit: $limit,
        );

        $this->line("Cards: {$result['cards']} · Images: {$result['images']} · Sets: ".implode(', ', $result['sets']));

        foreach ($result['sample'] as $c) {
            $this->line(sprintf(
                '  #%s  %s  [%s/%s] cost=%s pwr=%s ram=%s',
                $c['print_number'] ?? '?', $c['display_name'] ?? $c['name'],
                $c['color'] ?? '?', $c['card_type'] ?? '?',
                $c['cost'] ?? '-', $c['power'] ?? '-', $c['ram'] ?? '-',
            ));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }
}
