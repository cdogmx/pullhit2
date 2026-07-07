<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;

/**
 * On a sealed-product view, lazily pull its PriceCharting data (completed sales +
 * long-term monthly history) EXACTLY ONCE — if it's already been synced, it's
 * never re-pulled automatically (an admin "Refresh" forces a fresh pull, and a
 * batch sweep can re-warm en masse). Only sealed products resolve to a
 * PriceCharting page, so singles are skipped. Fire-and-forget (queued).
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

    /** Due only if it has never been synced — already-pulled items are left alone. */
    public function isDue(CatalogItem $item): bool
    {
        return $item->pc_synced_at === null;
    }
}
