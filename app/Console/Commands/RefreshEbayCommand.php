<?php

namespace App\Console\Commands;

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Console\Command;

/**
 * Manual / scheduled eBay sold-comp refresh (synchronous, bypasses the on-view
 * TTL). The safe way to backfill a set or smoke-test the live Oxylabs path.
 */
class RefreshEbayCommand extends Command
{
    protected $signature = 'valuation:refresh-ebay
        {--item= : A catalog_item id}
        {--set= : A set slug}
        {--exclude-rarity= : Comma-separated rarities to skip (e.g. "Common,Uncommon")}
        {--limit=25 : Max items}';

    protected $description = 'Pull eBay sold comps (via Oxylabs) and recompute market values';

    public function handle(IngestEbaySoldComps $ingest): int
    {
        // Each card pulls a large eBay HTML page through Oxylabs; backfilling a
        // whole set accumulates well past the default 128M.
        @ini_set('memory_limit', '1024M');

        $query = CatalogItem::query();

        if ($item = $this->option('item')) {
            $query->whereKey($item);
        } elseif ($set = $this->option('set')) {
            $query->whereHas('set', fn ($q) => $q->where('slug', $set));
        } else {
            $this->error('Provide --item or --set.');

            return self::FAILURE;
        }

        // Skip low-value rarities (e.g. Common/Uncommon) to conserve Oxylabs calls.
        if ($exclude = $this->option('exclude-rarity')) {
            $skip = array_filter(array_map('trim', explode(',', $exclude)));
            $query->whereNotIn('rarity', $skip);
        }

        $items = $query->limit((int) $this->option('limit'))->get();
        $total = 0;

        foreach ($items as $catalogItem) {
            $count = $ingest($catalogItem);
            $total += $count;
            $this->line("  {$catalogItem->name} {$catalogItem->number}: {$count} comps");
        }

        $this->info("Ingested {$total} eBay comps across {$items->count()} items.");

        return self::SUCCESS;
    }
}
