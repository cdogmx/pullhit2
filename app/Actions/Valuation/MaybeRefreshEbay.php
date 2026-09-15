<?php

namespace App\Actions\Valuation;

use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\ScrapeAgentPresence;
use Illuminate\Support\Carbon;

/**
 * On a card view, refresh its eBay comps if they're stale (older than
 * `view_refresh_hours`, default 12h) and the card isn't a low-value rarity we
 * skip. Non-blocking: the page renders the cached value and shows an "updating"
 * indicator. Returns whether a refresh is really in flight, so the page only
 * makes that promise when something is going to keep it.
 *
 * There are two ways to fetch now. The server can go through Oxylabs, which is
 * switched off while eBay requires a signed-in session for completed listings;
 * otherwise the work goes on the queue for the browser agent to pick up. The
 * agent is a browser extension on a desk and may simply be closed, so the queue
 * is only written — and "updating" only reported — when it has been heard from
 * recently. A spinner for work nobody is doing is worse than no spinner.
 */
class MaybeRefreshEbay
{
    public function __construct(
        private ScrapeAgentPresence $presence,
        private EbaySoldSource $source,
    ) {}

    public function __invoke(CatalogItem $item): bool
    {
        if ($this->isSkippedRarity($item) || ! $this->isDue($item)) {
            return false;
        }

        // The server's own fetcher, when it is switched on.
        if (config('valuation.ebay.enabled')) {
            RefreshEbaySoldComps::dispatch($item->id);

            return true;
        }

        return $this->queueForAgent($item);
    }

    /**
     * Put the card at the front of the agent's queue, if the agent is there.
     *
     * Priority, not position: a card someone is reading right now is worth more
     * than anything a routine top-up queued, and the agent works the queue in
     * priority order.
     */
    private function queueForAgent(CatalogItem $item): bool
    {
        if (! $this->presence->isLive()) {
            return false;
        }

        // Already waiting, or already being fetched — say "updating" without
        // queueing the same card twice.
        if (EbayScrapeJob::outstanding()->where('catalog_item_id', $item->id)->exists()) {
            return true;
        }

        EbayScrapeJob::create([
            'catalog_item_id' => $item->id,
            'url' => $this->source->soldSearchUrl($item),
            'status' => EbayScrapeJob::STATUS_PENDING,
            'priority' => self::VIEW_PRIORITY,
        ]);

        return true;
    }

    /**
     * Above anything `ebay:enqueue-sold` writes, which queues at 0 unless told
     * otherwise. Someone is looking at this card now.
     */
    private const VIEW_PRIORITY = 100;

    /** Low-value rarities (e.g. Common/Uncommon) aren't worth a sold-comp pull. */
    public function isSkippedRarity(CatalogItem $item): bool
    {
        $skip = (array) config('valuation.ebay.skip_rarities', []);
        $rarity = $item->attributes['rarity'] ?? null;

        return $rarity !== null && in_array($rarity, $skip, true);
    }

    public function isDue(CatalogItem $item): bool
    {
        if ($item->ebay_refreshed_at === null) {
            return true;
        }

        return $item->ebay_refreshed_at->lt($this->staleAfter($item));
    }

    /**
     * The moment a price becomes stale for this card.
     *
     * Usually the global view TTL, but a set in its first week is a different
     * market from the catalog around it — everything is being priced at once and
     * a twelve-hour-old figure is wrong by lunchtime. Such a set can carry its
     * own shorter cadence, which expires on its own date.
     */
    private function staleAfter(CatalogItem $item): Carbon
    {
        $item->loadMissing('set');

        if ($minutes = $item->set?->refreshMinutes()) {
            return Carbon::now()->subMinutes($minutes);
        }

        return Carbon::now()->subHours((int) config('valuation.ebay.view_refresh_hours', 12));
    }
}
