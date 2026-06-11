<?php

namespace App\Actions\Valuation;

use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * On a card view, refresh its eBay comps if they're stale (older than
 * `view_refresh_hours`, default 12h) and the card isn't a low-value rarity we
 * skip to conserve Oxylabs calls. Dispatches the queued job — non-blocking; the
 * page renders the cached value and shows an "updating" indicator. Returns
 * whether a refresh is now in flight (so the page can poll for the new values).
 */
class MaybeRefreshEbay
{
    public function __invoke(CatalogItem $item): bool
    {
        if (! config('valuation.ebay.enabled')
            || $this->isSkippedRarity($item)
            || ! $this->isDue($item)) {
            return false;
        }

        RefreshEbaySoldComps::dispatch($item->id);

        return true;
    }

    /** Low-value rarities (e.g. Common/Uncommon) aren't worth a paid sold-comp pull. */
    public function isSkippedRarity(CatalogItem $item): bool
    {
        $skip = (array) config('valuation.ebay.skip_rarities', []);
        $rarity = $item->attributes['rarity'] ?? null;

        return $rarity !== null && in_array($rarity, $skip, true);
    }

    public function isDue(CatalogItem $item): bool
    {
        $hours = (int) config('valuation.ebay.view_refresh_hours', 12);

        return $item->ebay_refreshed_at === null
            || $item->ebay_refreshed_at->lt(Carbon::now()->subHours($hours));
    }
}
