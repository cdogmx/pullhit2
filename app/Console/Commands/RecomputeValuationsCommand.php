<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use Illuminate\Console\Command;

/**
 * Recompute market_values from sale_observations. Run on a schedule (hot items
 * hourly, cold daily — §7) or ad hoc. Use --item to recompute a single item.
 */
class RecomputeValuationsCommand extends Command
{
    protected $signature = 'valuation:recompute {--item= : Recompute only this catalog_item id}';

    protected $description = 'Recompute market_values from sale_observations';

    public function handle(RecomputeCatalogItem $recompute): int
    {
        $query = CatalogItem::query()->whereHas('saleObservations');

        if ($item = $this->option('item')) {
            $query->whereKey($item);
        }

        $items = 0;
        $states = 0;
        $query->chunkById(100, function ($chunk) use ($recompute, &$items, &$states) {
            foreach ($chunk as $catalogItem) {
                $states += $recompute($catalogItem);
                $items++;
            }
        });

        $this->info("Recomputed {$states} priced states across {$items} items.");

        return self::SUCCESS;
    }
}
