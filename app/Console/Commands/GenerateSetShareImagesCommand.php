<?php

namespace App\Console\Commands;

use App\Actions\Catalog\GenerateSetShareImage;
use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Generate / refresh the social share (OG) image for each set — a collage of its
 * top cards by value. Sets without enough valued cards are skipped cheaply (a
 * single count query, no image downloads). Ranked by views so popular sets
 * refresh first. Scheduled weekly.
 */
class GenerateSetShareImagesCommand extends Command
{
    protected $signature = 'catalog:set-share-images
        {--set= : a single set id or slug}
        {--limit=400 : max images to generate this run}
        {--min=8 : minimum valued cards required to build an image}';

    protected $description = 'Generate/refresh set social share (OG) images';

    public function handle(GenerateSetShareImage $generate): int
    {
        @ini_set('memory_limit', '1024M');

        $min = (int) $this->option('min');
        $limit = (int) $this->option('limit');
        $made = 0;
        $skipped = 0;

        foreach ($this->resolveSets() as $set) {
            try {
                $url = $generate($set, $min);
            } catch (Throwable $e) {
                $this->error("  {$set->name} (#{$set->id}) failed: {$e->getMessage()}");

                continue;
            }

            if ($url) {
                $made++;
                $this->line("  ✓ {$set->name}");
            } else {
                $skipped++;
            }

            if ($made >= $limit) {
                break;
            }
        }

        $this->info("Generated {$made} set image(s); {$skipped} skipped (too few valued cards).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Set>
     */
    private function resolveSets(): Collection
    {
        if ($id = $this->option('set')) {
            $query = Set::query()->with('productLine');
            $set = is_numeric($id) ? $query->find((int) $id) : $query->where('slug', $id)->first();

            return $set ? collect([$set]) : collect();
        }

        // Rank by total card views so the most-visited sets refresh first.
        return Set::query()
            ->with('productLine')
            ->addSelect(['views' => CatalogItem::selectRaw('coalesce(sum(popularity), 0)')->whereColumn('set_id', 'sets.id')])
            ->orderByDesc('views')
            ->get();
    }
}
