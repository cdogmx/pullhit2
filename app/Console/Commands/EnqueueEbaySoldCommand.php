<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use App\Support\Ebay\EbaySoldSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fill the browser agent's queue with the cards whose prices have gone stalest.
 *
 * The agent is slow by design — one signed-in browser pacing itself like a
 * person — so the queue is a shortlist, not the catalog. Ordering therefore
 * matters more than it did when the server could fetch thousands a day: the
 * cards that get done are the ones at the front, so they are taken in order of
 * how much the site actually looks at them.
 *
 * Cards nobody values are skipped, not deprioritised. A card with no market
 * value has never been looked up, and spending a scarce fetch on it to discover
 * it has no comps either is the worst trade in the queue.
 */
class EnqueueEbaySoldCommand extends Command
{
    protected $signature = 'ebay:enqueue-sold
        {--limit=200 : how many cards to queue}
        {--stale-hours= : only cards not refreshed in this many hours (default: the view TTL)}
        {--priority=0 : higher runs first}
        {--include-unvalued : also queue cards that have never had a market value}
        {--truncate : clear jobs that have not been started yet first}';

    protected $description = 'Queue the stalest cards for the browser scrape agent';

    public function handle(EbaySoldSource $source): int
    {
        if ($this->option('truncate')) {
            $cleared = EbayScrapeJob::where('status', EbayScrapeJob::STATUS_PENDING)->delete();
            $this->line("Cleared {$cleared} unstarted job(s).");
        }

        $limit = max(1, (int) $this->option('limit'));
        $staleHours = (int) ($this->option('stale-hours') ?? config('valuation.ebay.view_refresh_hours', 12));
        $cutoff = now()->subHours($staleHours);

        // Anything already queued or in flight stays as it is — re-running this
        // should top the queue up, not stack a second copy of every card.
        $queued = EbayScrapeJob::outstanding()->pluck('catalog_item_id');

        $items = CatalogItem::query()
            ->with(['productLine', 'set'])
            ->whereNotIn('id', $queued)
            ->where(fn (Builder $q) => $q
                ->whereNull('ebay_refreshed_at')
                ->orWhere('ebay_refreshed_at', '<', $cutoff))
            // The rarities the on-view refresh already declines to spend on.
            // Written with the null case spelled out because `NOT IN` is NULL
            // for a NULL rarity, not true — which silently drops every card
            // whose rarity we do not hold, and those are exactly the ones with
            // the least data to begin with.
            ->when(
                $skip = (array) config('valuation.ebay.skip_rarities', []),
                fn (Builder $q) => $q->where(fn (Builder $r) => $r
                    ->whereNull('rarity')
                    ->orWhereNotIn('rarity', $skip)),
            )
            ->when(
                ! $this->option('include-unvalued'),
                fn (Builder $q) => $q->whereHas('marketValues'),
            )
            // Most-looked-at first. The agent is slow by design — one browser
            // pacing itself like a person — so the queue is a shortlist and the
            // cards that get done are the ones at the front. A price nobody
            // looks at being stale costs nothing; the one on the page someone
            // opens is the whole point.
            ->orderByDesc('popularity')
            // Then whoever was looked at most recently, so a card someone is
            // reading right now beats one from months ago on the same count.
            ->orderByRaw("COALESCE(last_viewed_at, '1970-01-01 00:00:00') DESC")
            // Then stalest, with never-fetched treated as infinitely stale.
            // Written as a COALESCE rather than relying on where NULLs happen to
            // sort, which differs between MySQL and SQLite — and the order here
            // is the order the agent works in, so it has to be the same in the
            // tests as in production.
            ->orderByRaw("COALESCE(ebay_refreshed_at, '1970-01-01 00:00:00') ASC")
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info('Nothing to queue — every card is fresher than '.$staleHours.'h.');

            return self::SUCCESS;
        }

        $priority = (int) $this->option('priority');
        $now = now();

        EbayScrapeJob::insert($items->map(fn (CatalogItem $item) => [
            'catalog_item_id' => $item->id,
            'url' => $source->soldSearchUrl($item),
            'status' => EbayScrapeJob::STATUS_PENDING,
            'priority' => $priority,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $this->info("Queued {$items->count()} card(s) at priority {$priority}.");
        $this->line('Outstanding queue: '.EbayScrapeJob::outstanding()->count());

        return self::SUCCESS;
    }
}
