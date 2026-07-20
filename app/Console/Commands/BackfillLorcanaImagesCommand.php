<?php

namespace App\Console\Commands;

use App\Actions\Catalog\BackfillLorcanaImages;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill missing Lorcana card images from TCGplayer's product CDN (keyed by
 * tcgplayer_product_id). Covers the PriceCharting-sourced sets and any image that
 * failed to download from lorcana-api. Re-runnable.
 */
class BackfillLorcanaImagesCommand extends Command
{
    protected $signature = 'catalog:lorcana-tcg-images
        {--limit=0 : cap the number of images to fetch (0 = all)}';

    protected $description = 'Backfill missing Lorcana card images from TCGplayer';

    public function handle(BackfillLorcanaImages $action): int
    {
        @ini_set('memory_limit', '1024M');
        $this->line('Backfilling Lorcana images from TCGplayer…');

        try {
            $r = $action((int) $this->option('limit'));
        } catch (Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("  candidates: {$r['candidates']}");
        $this->line("  stored: {$r['stored']}");
        $this->line("  no tcg id: {$r['no_id']}");
        $this->line("  failed: {$r['failed']}");

        return self::SUCCESS;
    }
}
