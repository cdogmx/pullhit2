<?php

namespace App\Console\Commands;

use App\Models\ScanFeedback;
use App\Models\ScanFingerprint;
use App\Support\Scanning\FingerprintCache;
use Illuminate\Console\Command;

/**
 * Teach the recognition cache from past scan corrections: every scan_feedback row
 * that carries a fingerprint + a corrected catalog item becomes a learned
 * fingerprint association (and heals the wrong cache hit it corrected), so
 * re-scanning those exact cards is an instant, AI-free match. Idempotent — skips
 * associations the cache already knows.
 */
class BackfillScanFingerprintsCommand extends Command
{
    protected $signature = 'scan:backfill-fingerprints {--dry : Report what would change without writing}';

    protected $description = 'Seed the recognition cache from past scan corrections (scan_feedback → fingerprints)';

    public function handle(FingerprintCache $cache): int
    {
        $dry = (bool) $this->option('dry');

        $rows = ScanFeedback::query()
            ->whereNotNull('phash')
            ->whereNotNull('corrected_catalog_item_id')
            ->orderBy('id')
            ->get();

        $taught = 0;
        $healed = 0;
        $skipped = 0;

        foreach ($rows as $f) {
            $exists = ScanFingerprint::where('phash', $f->phash)
                ->where('catalog_item_id', $f->corrected_catalog_item_id)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            if (! $dry) {
                $cache->record((string) $f->phash, (int) $f->corrected_catalog_item_id, $f->user_id);

                // Heal the wrong CACHE association this correction overrode, if any.
                if ($f->detected_catalog_item_id && $f->source === 'cache') {
                    $cache->demote((string) $f->phash, (int) $f->detected_catalog_item_id);
                    $healed++;
                }
            }

            $taught++;
        }

        $prefix = $dry ? '[dry] ' : '';
        $this->info("{$prefix}Corrections seen: {$rows->count()}. Taught: {$taught}, healed wrong cache hits: {$healed}, already known: {$skipped}.");

        return self::SUCCESS;
    }
}
