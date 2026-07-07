<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * On a sealed-product view, lazily pull its PriceCharting data (completed sales +
 * long-term monthly history) once — then not again until the refresh window
 * elapses. Only sealed products resolve to a PriceCharting page, so singles are
 * skipped. Fire-and-forget (queued); the page renders whatever is cached.
 */
class MaybeRefreshPricecharting
{
    public function __invoke(CatalogItem $item): void
    {
        if (! config('valuation.pricecharting.enabled', true)
            || $item->item_type !== ItemType::Sealed
            || ! $this->isDue($item)) {
            return;
        }

        RefreshPricechartingData::dispatch($item->id);
    }

    public function isDue(CatalogItem $item): bool
    {
        $days = (int) config('valuation.pricecharting.view_refresh_days', 30);

        return $item->pc_synced_at === null
            || $item->pc_synced_at->lt(Carbon::now()->subDays($days));
    }
}
