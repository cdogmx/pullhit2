<?php

namespace App\Actions\Valuation;

use App\Jobs\RefreshForSaleListings;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * On a card view, refresh its "for sale" asks if they're stale. The sources
 * (eBay Browse, TCGCSV) are free, so there's no spend cap — only a freshness TTL
 * and the same low-value-rarity skip the sold refresh uses. Non-blocking: the
 * page renders the cached for-sale value and the job updates it in the background.
 */
class MaybeRefreshForSale
{
    public function __invoke(CatalogItem $item): bool
    {
        if ($this->isSkippedRarity($item) || ! $this->isDue($item)) {
            return false;
        }

        RefreshForSaleListings::dispatch($item->id);

        return true;
    }

    protected function isSkippedRarity(CatalogItem $item): bool
    {
        $skip = (array) config('valuation.ebay.skip_rarities', []);
        $rarity = $item->attributes['rarity'] ?? null;

        return $rarity !== null && in_array($rarity, $skip, true);
    }

    protected function isDue(CatalogItem $item): bool
    {
        $hours = (int) config('valuation.for_sale.view_refresh_hours', 6);

        return $item->for_sale_refreshed_at === null
            || $item->for_sale_refreshed_at->lt(Carbon::now()->subHours($hours));
    }
}
