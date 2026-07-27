<?php

namespace App\Jobs;

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Models\CatalogItem;
use App\Support\Ebay\OxylabsBudgetException;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one catalog item's eBay sold comps via Oxylabs. Cost guards:
 *  - skip if the item was already refreshed within the freshness window
 *    (so a burst of views can't fire duplicate paid fetches),
 *  - per-item cache lock so concurrent views don't double-fetch,
 *  - a daily request budget enforced inside OxylabsClient, which bills per
 *    delivered result (a retried fetch spends more than one),
 *  - failures are logged, never fatal — the cached value stands.
 */
class RefreshEbaySoldComps implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $catalogItemId,
        public bool $force = false,
    ) {}

    public function handle(IngestEbaySoldComps $ingest, OxylabsClient $oxylabs): void
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
            // Re-read under the lock: another job may have just refreshed this
            // item (duplicate dispatch from a burst of views). If it's fresh
            // within the window, skip — no Oxylabs call, no cap spend. An admin
            // "Refresh now" passes force to bypass this (but still honours the cap).
            $item->refresh();
            $hours = (int) config('valuation.ebay.view_refresh_hours', 12);
            if (! $this->force
                && $item->ebay_refreshed_at !== null
                && $item->ebay_refreshed_at->gt(Carbon::now()->subHours($hours))) {
                return;
            }

            // Advisory pre-check only — OxylabsClient bills and enforces per
            // request, so a fetch that retries can't quietly overspend the cap.
            if (! $oxylabs->hasBudget(OxylabsClient::BUDGET_EBAY)) {
                Log::info('eBay daily request cap reached; skipping refresh.', ['item' => $item->id]);

                return;
            }

            $ingest($item);
        } catch (OxylabsBudgetException $e) {
            // Ran out mid-fetch (another worker spent the rest). Expected, not a bug.
            Log::info('eBay daily request cap reached mid-fetch.', ['item' => $item->id]);
        } catch (Throwable $e) {
            report($e); // keep the existing value; try again next time
        } finally {
            $lock->release();
        }
    }
}
