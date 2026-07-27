<?php

namespace App\Jobs;

use App\Actions\Valuation\IngestPricechartingComps;
use App\Models\CatalogItem;
use App\Support\Ebay\OxylabsBudgetException;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one catalog item's PriceCharting data (completed sales + long-term
 * monthly history) via Oxylabs, once per card. Cost guards mirror the eBay job:
 * per-item lock, a refresh-window skip (PriceCharting's monthly series is slow to
 * change), and PriceCharting's own Oxylabs daily budget — enforced inside
 * OxylabsClient, which bills per delivered result. Failures are logged, never fatal.
 */
class RefreshPricechartingData implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $catalogItemId,
        public bool $force = false,
    ) {}

    public function handle(IngestPricechartingComps $ingest, OxylabsClient $oxylabs): void
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
            // Re-read under the lock; skip if it's already been synced (once-ever
            // on the lazy path — another view may have just done it). --force /
            // the admin refresh bypass this to re-pull deliberately.
            $item->refresh();
            if (! $this->force && $item->pc_synced_at !== null) {
                return;
            }

            // PriceCharting's own Oxylabs daily budget (separate from eBay's, so a
            // bulk sweep can't starve the interactive eBay on-view refresh).
            // Advisory pre-check; OxylabsClient enforces it per request.
            if (! $oxylabs->hasBudget(OxylabsClient::BUDGET_PRICECHARTING)) {
                Log::info('PriceCharting daily cap reached; skipping fetch.', ['item' => $item->id]);

                return;
            }

            $ingest($item);
        } catch (OxylabsBudgetException $e) {
            Log::info('PriceCharting daily cap reached mid-fetch.', ['item' => $item->id]);
        } catch (Throwable $e) {
            report($e); // keep existing data; try again next time
        } finally {
            $lock->release();
        }
    }
}
