<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportToppsSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Import a Topps collectibles set from its checklist + odds, as JSON.
 *
 * Topps publishes both as PDFs per release. Transcribing them into the JSON
 * this reads is the manual step; everything after is repeatable, and the shape
 * is the same for every product they ship, so the next release is the same job.
 *
 * Idempotent, because CreateCatalogItem upserts on identity_hash — re-running
 * after correcting a name updates that row rather than making a second.
 */
class ImportToppsSetCommand extends Command
{
    protected $signature = 'catalog:import-topps
        {file : path to the set JSON (checklist + odds)}
        {--base-only : create only the base printing of each card, no parallels}
        {--dry-run : report what would be created, write nothing}';

    protected $description = 'Import a Topps collectibles set (checklist + odds) into the catalog';

    public function handle(ImportToppsSet $import): int
    {
        @ini_set('memory_limit', '1024M');

        $path = (string) $this->argument('file');

        if (! File::exists($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error("Could not read {$path}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $withParallels = ! $this->option('base-only');

        // Counted before writing, so the size of the job is visible up front:
        // a complete Topps set runs to thousands of rows and nobody should
        // discover that halfway through.
        [$cards, $printings] = $this->tally($data, $withParallels);

        $this->line(sprintf(
            '%s — %s card(s), %s printing(s)%s',
            $data['set']['name'] ?? 'set',
            number_format($cards),
            number_format($printings),
            $withParallels ? '' : ' (base only)',
        ));

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($printings);
        $bar->start();

        $result = $import($data, $withParallels);

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Imported %s: %s card(s) across %s printing(s).',
            $result['set'],
            number_format($result['cards']),
            number_format($result['printings']),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    protected function tally(array $data, bool $withParallels): array
    {
        $cards = 0;
        $printings = 0;

        foreach ($data['subsets'] ?? [] as $subset) {
            $n = count($subset['cards'] ?? []);
            $treatments = 1 + ($withParallels ? count($subset['parallels'] ?? []) : 0);

            $cards += $n;
            $printings += $n * $treatments;
        }

        return [$cards, $printings];
    }
}
