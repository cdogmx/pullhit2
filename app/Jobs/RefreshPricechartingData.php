<?php

namespace App\Jobs;

use App\Actions\Valuation\IngestPricechartingComps;
use App\Models\CatalogItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one catalog item's PriceCharting data (completed sales + long-term
 * monthly history) via Oxylabs, once per card. Cost guards mirror the eBay job:
 * per-item lock, a refresh-window skip (PriceCharting's monthly series is slow to
 * change), and the SHARED Oxylabs daily cap. Failures are logged, never fatal.
 */
class RefreshPricechartingData implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $catalogItemId,
        public bool $force = false,
    ) {}

    public function handle(IngestPricechartingComps $ingest): void
    {
        if (! config('valuation.pricecharting.enabled', true)) {
            return;
        }

        $item = CatalogItem::find($this->catalogItemId);
        if (! $item) {
            return;
        }

        $lock = Cache::lock("pricecharting:item:{$item->id}", 120);
        if (! $lock->get()) {
            return; // a fetch for this item is already in flight
        }

        try {
            // Re-read under the lock; skip if another view just synced it.
            $item->refresh();
            $days = (int) config('valuation.pricecharting.view_refresh_days', 30);
            if (! $this->force
                && $item->pc_synced_at !== null
                && $item->pc_synced_at->gt(Carbon::now()->subDays($days))) {
                return;
            }

            // PriceCharting's own Oxylabs daily budget (separate from eBay's, so a
            // bulk sweep can't starve the interactive eBay on-view refresh).
            $key = 'pricecharting:daily:'.Carbon::now()->toDateString();
            Cache::add($key, 0, Carbon::now()->endOfDay());

            if ((int) Cache::get($key, 0) >= (int) config('valuation.pricecharting.daily_cap')) {
                Log::info('PriceCharting daily cap reached; skipping fetch.', ['item' => $item->id]);

                return;
            }

            Cache::increment($key);
            $ingest($item);
        } catch (Throwable $e) {
            report($e); // keep existing data; try again next time
        } finally {
            $lock->release();
        }
    }
}
