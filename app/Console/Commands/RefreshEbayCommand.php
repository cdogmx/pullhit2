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
    protected $signature = 'valuation:refresh-ebay {--item= : A catalog_item id} {--set= : A set slug} {--limit=25 : Max items}';

    protected $description = 'Pull eBay sold comps (via Oxylabs) and recompute market values';

    public function handle(IngestEbaySoldComps $ingest): int
    {
        $query = CatalogItem::query();

        if ($item = $this->option('item')) {
            $query->whereKey($item);
        } elseif ($set = $this->option('set')) {
            $query->whereHas('set', fn ($q) => $q->where('slug', $set));
        } else {
            $this->error('Provide --item or --set.');

            return self::FAILURE;
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
