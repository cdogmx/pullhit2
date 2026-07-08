<?php

namespace App\Http\Controllers\Web;

use App\Actions\Valuation\PriceHistory;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compare up to 5 cards' value over time on one chart. The selection lives in the
 * URL (?ids=1,2,3) so a comparison is shareable and server-rendered; each item
 * ships with its weekly-median price-history series (App\Actions\Valuation\
 * PriceHistory), the same data behind the card page's chart.
 */
class CompareController extends Controller
{
    /** How many cards can be compared at once. */
    private const MAX_ITEMS = 5;

    /** Days of history to ship (covers the widest 1Y window with headroom). */
    private const HISTORY_DAYS = 400;

    public function __invoke(Request $request, PriceHistory $history): Response
    {
        $ids = $this->requestedIds($request);

        $items = CatalogItem::query()
            ->whereIn('id', $ids)
            ->with(['productLine:id,slug', 'set:id,name,slug', 'defaultMarketValue'])
            ->get()
            // Preserve the order the ids were given (sortBy the id's position).
            ->sortBy(fn (CatalogItem $item) => $ids->search($item->id))
            ->map(fn (CatalogItem $item) => [
                'id' => $item->id,
                'name' => $item->display_name ?: $item->name,
                'set_name' => $item->set?->name,
                'url' => $item->path(),
                'image' => $item->primary_image_path
                    ?? ($item->getAttribute('external_ids')['ptcgio_image'] ?? null),
                'latest' => $item->defaultMarketValue?->median,
                'series' => $history($item, self::HISTORY_DAYS),
                // Long-term monthly series (PriceCharting) for the "Max" window —
                // the ungraded line; read-only; the card page owns pulling it.
                'series_long' => $item->longTermHistory('ungraded'),
            ])
            ->values();

        return Inertia::render('catalog/compare', [
            'items' => $items,
            'maxItems' => self::MAX_ITEMS,
            'meta' => [
                'title' => $this->title($items),
                'description' => 'Compare up to 5 trading cards side by side — track and contrast their market value over time on CardFoo.',
            ],
        ]);
    }

    /**
     * Parse ?ids=1,2,3 into a de-duplicated, capped list of catalog item ids.
     *
     * @return Collection<int, int>
     */
    private function requestedIds(Request $request): Collection
    {
        return collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($raw) => (int) trim($raw))
            ->filter()
            ->unique()
            ->take(self::MAX_ITEMS)
            ->values();
    }

    /**
     * A specific page title when items are selected (better SEO/share text).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function title(Collection $items): string
    {
        if ($items->isEmpty()) {
            return 'Compare card values | CardFoo';
        }

        return $items->pluck('name')->implode(' vs ').' | CardFoo';
    }
}
