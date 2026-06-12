<?php

namespace App\Console\Commands;

use App\Actions\Import\MatchPricechartingRow;
use App\Actions\Import\ParsePricechartingCsv;
use Illuminate\Console\Command;

/**
 * Dry-run a PriceCharting CSV import against the live catalog: parse + match
 * every row and report how much we can ingest and exactly which sets we're
 * missing. No writes.
 */
class ImportPreviewCommand extends Command
{
    protected $signature = 'collection:import-preview {path : Path to a PriceCharting export CSV}';

    protected $description = 'Preview a PriceCharting CSV import: parse + match against the catalog and report stats.';

    public function handle(ParsePricechartingCsv $parse, MatchPricechartingRow $match): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rows = $parse((string) file_get_contents($path));
        $this->info(count($rows).' rows parsed.');

        $status = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        $reasons = [];
        $unmatchedBuckets = [];

        foreach ($rows as $row) {
            $result = $match($row);
            $status[$result->status] = ($status[$result->status] ?? 0) + 1;
            $reasons[$result->reason] = ($reasons[$result->reason] ?? 0) + 1;

            if ($result->status === 'unmatched') {
                $key = $row->language.'  '.$row->setName;
                $unmatchedBuckets[$key] = ($unmatchedBuckets[$key] ?? 0) + 1;
            }
        }

        $total = max(1, array_sum($status));
        $this->newLine();
        $this->table(
            ['Status', 'Count', '%'],
            collect($status)->map(fn ($c, $s) => [$s, $c, round($c / $total * 100, 1).'%'])->values(),
        );

        $this->newLine();
        $this->line('Reasons:');
        arsort($reasons);
        foreach ($reasons as $reason => $count) {
            $this->line(sprintf('  %5d  %s', $count, $reason));
        }

        $this->newLine();
        $this->line('Top unmatched set / language buckets:');
        arsort($unmatchedBuckets);
        foreach (array_slice($unmatchedBuckets, 0, 25, true) as $bucket => $count) {
            $this->line(sprintf('  %5d  %s', $count, $bucket));
        }

        return self::SUCCESS;
    }
}
