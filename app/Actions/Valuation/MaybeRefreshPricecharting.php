<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;

/**
 * On a card view, lazily pull its PriceCharting data (completed sales + long-term
 * monthly history, per grade tier for singles) EXACTLY ONCE — if it's already
 * been synced, it's never re-pulled automatically (an admin "Refresh" forces a
 * fresh pull, and a batch sweep can re-warm en masse). Both singles and sealed
 * products resolve to a PriceCharting page. Fire-and-forget (queued).
 */
class MaybeRefreshPricecharting
{
    public function __invoke(CatalogItem $item): void
    {
        if (! config('valuation.pricecharting.enabled', true)
            || ! in_array($item->item_type, [ItemType::Single, ItemType::Sealed], true)
            || ! $this->isDue($item)) {
            return;
        }

        RefreshPricechartingData::dispatch($item->id);
    }

    /** Due only if it has never been synced — already-pulled items are left alone. */
    public function isDue(CatalogItem $item): bool
    {
        return $item->pc_synced_at === null;
    }
}
