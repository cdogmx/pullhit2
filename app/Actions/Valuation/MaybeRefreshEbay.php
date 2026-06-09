<?php

namespace App\Actions\Valuation;

use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * Decides whether a card's eBay data is stale enough to refresh, with the TTL
 * scaled by popularity (hot cards refresh more often). Dispatches the queued job
 * when due — non-blocking; the page renders the cached value.
 */
class MaybeRefreshEbay
{
    public function __invoke(CatalogItem $item): void
    {
        if (! config('valuation.ebay.enabled')) {
            return;
        }

        if ($this->isDue($item)) {
            RefreshEbaySoldComps::dispatch($item->id);
        }
    }

    public function isDue(CatalogItem $item): bool
    {
        if ($item->ebay_refreshed_at === null) {
            return true;
        }

        return $item->ebay_refreshed_at->lt(Carbon::now()->subHours($this->ttlHours($item)));
    }

    protected function ttlHours(CatalogItem $item): int
    {
        $ebay = config('valuation.ebay');

        return match (true) {
            $item->popularity >= $ebay['popularity']['hot'] => (int) $ebay['ttl']['hot_hours'],
            $item->popularity >= $ebay['popularity']['warm'] => (int) $ebay['ttl']['warm_hours'],
            default => (int) $ebay['ttl']['cold_days'] * 24,
        };
    }
}
