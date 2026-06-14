<?php

namespace App\Console\Commands;

use App\Models\PricechartingProduct;
use App\Support\Pricecharting\CsvClient;
use App\Support\Pricecharting\PriceGuideParser;
use App\Support\Pricecharting\SetMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ingest the PriceCharting Legendary price-guide CSV into `pricecharting_products`
 * — the reference set the reconciliation diffs against. Fetches the CSV directly
 * by default (schedulable); pass --path to use a local file. Idempotent upsert
 * keyed on the PriceCharting product id.
 */
class PricechartingImportCommand extends Command
{
    protected $signature = 'catalog:pricecharting-import
        {--path= : Read a local CSV instead of downloading}
        {--category=pokemon-cards : PriceCharting category}
        {--report-sets : Print the console→set mapping and exit without importing}';

    protected $description = 'Import the PriceCharting price-guide CSV into the reconciliation reference table.';

    public function handle(CsvClient $client, PriceGuideParser $parse, SetMapper $mapper): int
    {
        @ini_set('memory_limit', '1024M');

        $path = $this->option('path');
        $this->line($path ? "Reading {$path}…" : 'Downloading PriceCharting price guide…');
        $csv = $path ? (string) file_get_contents($path) : $client->fetch((string) $this->option('category'));

        if ($this->option('report-sets')) {
            return $this->reportSets($parse($csv), $mapper);
        }

        $batch = [];
        $total = 0;
        $mapped = 0;
        $sealed = 0;
        $now = Carbon::now();

        foreach ($parse($csv) as $row) {
            $row['set_id'] = $mapper->resolve($row['set_name'] ?? '', $row['language'] ?? 'en');
            $row['release_date'] = self::date($row['release_date'] ?? null);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $total++;
            $mapped += $row['set_id'] ? 1 : 0;
            $sealed += $row['is_sealed'] ? 1 : 0;

            $batch[] = $row;
            if (count($batch) >= 1000) {
                $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }
        if ($batch !== []) {
            $this->flush($batch);
        }

        $this->newLine();
        $this->info("Imported {$total} products — {$mapped} mapped to a set, {$sealed} sealed.");

        return self::SUCCESS;
    }

    /** Normalize PriceCharting's release-date to an ISO date, or null. */
    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  array<int, array<string, mixed>>  $batch */
    private function flush(array $batch): void
    {
        PricechartingProduct::upsert(
            $batch,
            ['pc_id'],
            ['console_name', 'product_name', 'language', 'set_name', 'set_id', 'card_name', 'number',
                'edition', 'variant', 'finish', 'is_sealed', 'tcg_id', 'price_ungraded', 'price_cib',
                'price_grade8', 'price_grade9', 'price_grade95', 'price_psa10', 'price_bgs10',
                'sales_volume', 'release_date', 'updated_at'],
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function reportSets(iterable $rows, SetMapper $mapper): int
    {
        $mapped = [];
        $unmapped = [];
        foreach ($rows as $row) {
            $console = $row['console_name'];
            if ($mapper->resolve($row['set_name'] ?? '', $row['language'] ?? 'en')) {
                $mapped[$console] = ($mapped[$console] ?? 0) + 1;
            } else {
                $unmapped[$console] = ($unmapped[$console] ?? 0) + 1;
            }
        }

        arsort($unmapped);
        $this->info(count($mapped).' consoles mapped, '.count($unmapped).' unmapped.');
        $this->line('--- UNMAPPED (top 60 by product count; many are non-catalog Topps/promos) ---');
        foreach (array_slice($unmapped, 0, 60, true) as $console => $count) {
            $this->line("  {$count}\t{$console}");
        }

        return self::SUCCESS;
    }
}
