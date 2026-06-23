<?php

namespace App\Console\Commands;

use App\Actions\Catalog\BackfillOnePieceImages;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill One Piece card images from the official card-list CDN. With no
 * setId, sweeps every One Piece set of the chosen language that still has
 * image-less numbered cards. Idempotent.
 */
class BackfillOnePieceImagesCommand extends Command
{
    protected $signature = 'catalog:backfill-op-images
        {setId? : a single Set id; omit to sweep all One Piece sets of --lang}
        {--lang=ja : set language to target (ja = Japanese art, en = English art)}
        {--dry-run : report candidates without downloading}';

    protected $description = 'Backfill One Piece images from the official card-list CDN';

    public function handle(BackfillOnePieceImages $backfill): int
    {
        @ini_set('memory_limit', '1024M');

        $lang = (string) $this->option('lang');
        $base = $lang === 'en' ? BackfillOnePieceImages::EN_BASE : BackfillOnePieceImages::JP_BASE;
        $dryRun = (bool) $this->option('dry-run');

        $sets = $this->resolveSets($lang);

        if ($sets->isEmpty()) {
            $this->warn('No matching One Piece sets found.');

            return self::SUCCESS;
        }

        $totStored = 0;
        $allMissing = [];

        foreach ($sets as $set) {
            try {
                $r = $backfill($set, $base, $dryRun);
            } catch (Throwable $e) {
                $this->error("  {$set->name} (id={$set->id}) failed: {$e->getMessage()}");

                continue;
            }

            $verb = $dryRun ? 'would store' : 'stored';
            $this->line("{$set->name} (id={$set->id}): candidates {$r['candidates']}, {$verb} {$r['stored']}".
                ($r['missing'] ? ', missing '.count($r['missing']) : ''));
            $totStored += $r['stored'];
            $allMissing = array_merge($allMissing, $r['missing']);
        }

        $this->info(($dryRun ? 'Would store' : 'Stored')." {$totStored} images across {$sets->count()} set(s).");

        if ($allMissing) {
            $this->warn('Numbers the CDN did not host ('.count($allMissing).'): '.implode(', ', array_slice($allMissing, 0, 50)).
                (count($allMissing) > 50 ? ' …' : ''));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Set>
     */
    private function resolveSets(string $lang)
    {
        if ($id = $this->argument('setId')) {
            return Set::where('id', (int) $id)->get();
        }

        $lineId = ProductLine::where('name', 'One Piece Card Game')->value('id');

        return Set::where('product_line_id', $lineId)->where('language', $lang)->orderBy('id')->get();
    }
}
