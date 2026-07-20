<?php

namespace App\Jobs;

use App\Actions\Valuation\IngestForSaleListings;
use App\Models\CatalogItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one card's "for sale" valuation from current asks (eBay Browse +
 * TCGplayer via TCGCSV — both free, so no spend cap). A per-item lock plus the
 * freshness-window re-check keep a burst of views from doing duplicate work.
 * Failures are logged, never fatal — the cached for-sale value stands.
 */
class RefreshForSaleListings implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $catalogItemId,
        public bool $force = false,
    ) {}

    public function handle(IngestForSaleListings $ingest): void
    {
        $item = CatalogItem::with('set', 'productLine')->find($this->catalogItemId);
        if (! $item) {
            return;
        }

        $lock = Cache::lock("for-sale:item:{$item->id}", 120);
        if (! $lock->get()) {
            return; // a refresh for this item is already in flight
        }

        try {
            $hours = (int) config('valuation.for_sale.view_refresh_hours', 6);
            $fresh = $item->for_sale_refreshed_at !== null
                && $item->for_sale_refreshed_at->gt(Carbon::now()->subHours($hours));

            if (! $this->force && $fresh) {
                return;
            }

            $ingest($item);
        } catch (Throwable $e) {
            Log::warning('For-sale refresh failed.', ['item' => $item->id, 'error' => $e->getMessage()]);
        } finally {
            $lock->release();
        }
    }
}
