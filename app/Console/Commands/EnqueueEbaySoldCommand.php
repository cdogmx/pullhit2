<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\EbayScrapeJob;
use App\Models\Set;
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
 *
 * --set overrides that, because naming a set is the one case where the absence
 * of a value is the reason to fetch rather than a reason not to: a set that was
 * just imported has no values on any of its cards, and "queue this set" that
 * queued three of eighteen would be answering a different question.
 */
class EnqueueEbaySoldCommand extends Command
{
    protected $signature = 'ebay:enqueue-sold
        {--set= : only this set, by slug — implies --include-unvalued}
        {--collection= : only the cards in this collection, as "username/slug" or an id — implies --include-unvalued}
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

        $set = null;

        if ($slug = $this->option('set')) {
            $set = Set::where('slug', $slug)->first();

            if (! $set) {
                $this->error("No set with slug {$slug}.");

                return self::FAILURE;
            }
        }

        $collection = null;

        if ($ref = $this->option('collection')) {
            $collection = $this->resolveCollection($ref);

            if (! $collection) {
                $this->error("No collection matching \"{$ref}\". Use \"username/slug\", or an id.");

                return self::FAILURE;
            }
        }

        // Naming a list is naming the cards somebody actually cares about, so
        // both --set and --collection are treated as the stronger signal.
        $named = $set !== null || $collection !== null;

        $limit = max(1, (int) $this->option('limit'));
        $staleHours = (int) ($this->option('stale-hours') ?? config('valuation.ebay.view_refresh_hours', 12));
        $cutoff = now()->subHours($staleHours);

        // Anything already queued or in flight stays as it is — re-running this
        // should top the queue up, not stack a second copy of every card.
        //
        // Nulls excluded, and that is not a tidiness measure. A sweep job is a
        // broad search with no card attached, so its catalog_item_id is NULL —
        // and `id NOT IN (1, 2, NULL)` is NULL for every row, not true. With one
        // sweep outstanding this list matched nothing at all: 0 of 71,490 cards
        // survived the filter and the command queued nothing, while reporting
        // "every card is fresher than 12h" as though all was well.
        $queued = EbayScrapeJob::outstanding()
            ->whereNotNull('catalog_item_id')
            ->pluck('catalog_item_id');

        $items = CatalogItem::query()
            ->with(['productLine', 'set'])
            ->whereNotIn('id', $queued)
            ->when($set, fn (Builder $q) => $q->where('set_id', $set->id))
            ->when($collection, fn (Builder $q) => $q->whereIn(
                'id',
                $collection->items()->select('catalog_item_id'),
            ))
            ->where(fn (Builder $q) => $q
                ->whereNull('ebay_refreshed_at')
                ->orWhere('ebay_refreshed_at', '<', $cutoff))
            // The rarities the on-view refresh already declines to spend on.
            // Written with the null case spelled out because `NOT IN` is NULL
            // for a NULL rarity, not true — which silently drops every card
            // whose rarity we do not hold, and those are exactly the ones with
            // the least data to begin with.
            // A collection is a hand-picked list of cards somebody owns or is
            // selling, so every one of them is wanted — including the commons
            // the catalog-wide heuristic declines to spend on. A set is not:
            // it is a whole printing, most of which is commons, and queueing
            // those would bury the cards the set was named for.
            ->when(
                ! $collection && ($skip = (array) config('valuation.ebay.skip_rarities', [])),
                fn (Builder $q) => $q->where(fn (Builder $r) => $r
                    ->whereNull('rarity')
                    ->orWhereNotIn('rarity', $skip)),
            )
            ->when(
                ! $this->option('include-unvalued') && ! $named,
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
            $where = $set?->name ?? $collection?->name;

            $this->info($where
                ? "Nothing to queue — every card in {$where} is already queued or fresher than {$staleHours}h."
                : 'Nothing to queue — every card is fresher than '.$staleHours.'h.');

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

    /**
     * Find a collection from what a person would paste.
     *
     * "CardFoo/for-sale" is the shape of its public URL, which is how anyone
     * asking for this actually holds the thing. A bare id works too. A bare
     * slug does not: collection slugs are only unique per user, and "for-sale"
     * belongs to as many people as have made one — queueing a stranger's cards
     * because two lists share a name is not a mistake worth allowing.
     */
    private function resolveCollection(string $ref): ?Collection
    {
        if (ctype_digit($ref)) {
            return Collection::find((int) $ref);
        }

        if (! str_contains($ref, '/')) {
            return null;
        }

        [$username, $slug] = array_map('trim', explode('/', $ref, 2));

        return Collection::query()
            ->whereHas('user', fn (Builder $q) => $q->where('username', $username))
            ->where('slug', $slug)
            ->first();
    }
}
