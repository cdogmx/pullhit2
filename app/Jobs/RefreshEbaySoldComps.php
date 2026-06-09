<?php

namespace App\Jobs;

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one catalog item's eBay sold comps via Oxylabs. Cost guards:
 *  - per-item cache lock so concurrent views don't double-fetch,
 *  - a global daily request cap (Oxylabs bills per call),
 *  - failures are logged, never fatal — the cached value stands.
 */
class RefreshEbaySoldComps implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $catalogItemId) {}

    public function handle(IngestEbaySoldComps $ingest): void
    {
        if (! config('valuation.ebay.enabled')) {
            return;
        }

        $item = CatalogItem::find($this->catalogItemId);
        if (! $item) {
            return;
        }

        $lock = Cache::lock("ebay:item:{$item->id}", 120);
        if (! $lock->get()) {
            return; // a fetch for this item is already in flight
        }

        try {
            $key = 'ebay:daily:'.Carbon::now()->toDateString();
            Cache::add($key, 0, Carbon::now()->endOfDay());

            if ((int) Cache::get($key, 0) >= (int) config('valuation.ebay.daily_cap')) {
                Log::info('eBay daily request cap reached; skipping refresh.', ['item' => $item->id]);

                return;
            }

            Cache::increment($key);
            $ingest($item);
        } catch (Throwable $e) {
            report($e); // keep the existing value; try again next time
        } finally {
            $lock->release();
        }
    }
}
