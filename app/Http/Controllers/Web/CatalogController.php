<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\BrowseTiles;
use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\SearchCatalog;
use App\Actions\Catalog\ShowCatalogItem;
use App\Actions\Valuation\MaybeRefreshEbay;
use App\Actions\Valuation\PriceHistory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\GradingCompany;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public catalog browse/search (Inertia). Thin — delegates to the same Actions
 * the API uses (§2).
 */
class CatalogController extends Controller
{
    public function index(
        SearchCatalogRequest $request,
        SearchCatalog $search,
        CatalogFilterOptions $options,
    ): Response {
        return $this->renderBrowse($request->filters(), $search, $options);
    }

    /** SEO landing for a product line, e.g. /browse/pokemon. */
    public function browseLine(
        SearchCatalogRequest $request,
        SearchCatalog $search,
        CatalogFilterOptions $options,
        string $productLine,
    ): Response {
        $line = ProductLine::where('slug', $productLine)->firstOrFail();

        $filters = array_merge($request->filters(), ['product_line' => $line->slug]);

        return $this->renderBrowse($filters, $search, $options, seo: [
            'title' => "{$line->name} cards & prices",
            'heading' => "{$line->name}",
        ]);
    }

    /** SEO landing for a set, e.g. /browse/pokemon/surging-sparks. */
    public function browseSet(
        SearchCatalogRequest $request,
        SearchCatalog $search,
        CatalogFilterOptions $options,
        string $productLine,
        string $set,
    ): Response {
        $line = ProductLine::where('slug', $productLine)->firstOrFail();
        $setModel = Set::where('slug', $set)->where('product_line_id', $line->id)->firstOrFail();

        $filters = array_merge($request->filters(), [
            'product_line' => $line->slug,
            'set' => $setModel->slug,
        ]);

        return $this->renderBrowse($filters, $search, $options, seo: [
            'title' => "{$setModel->name} — {$line->name} cards & prices",
            'heading' => $setModel->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{title: string, heading: string}|null  $seo
     */
    private function renderBrowse(array $filters, SearchCatalog $search, CatalogFilterOptions $options, ?array $seo = null): Response
    {
        $tiles = app(BrowseTiles::class);

        // Resolve the drill-down level. A tier collapses when it adds no choice:
        // a brand with ≤1 series jumps straight to its sets; a set with no named
        // subsets jumps straight to its cards.
        $mode = $this->browseMode($filters);
        $tileData = [];

        if ($mode === 'series') {
            $tileData = $tiles('series', $filters);
            if (count($tileData) <= 1) {
                $mode = 'sets';
                $tileData = $tiles('sets', $filters);
            }
        } elseif ($mode === 'subsets') {
            $tileData = $tiles('subsets', $filters);
            if (count($tileData) <= 1) {
                $mode = 'cards';
            }
        } elseif ($mode !== 'cards') {
            $tileData = $tiles($mode, $filters);
        }

        // The admin-authored description for the current brand (series view) or
        // set (subset/cards view), shown under the heading.
        $blurb = match (true) {
            $mode === 'series' && ! empty($filters['product_line']) => ProductLine::where('slug', $filters['product_line'])->value('description'),
            in_array($mode, ['subsets', 'cards'], true) && ! empty($filters['set']) => Set::where('slug', $filters['set'])->value('description'),
            default => null,
        };

        $common = [
            'mode' => $mode,
            'blurb' => $blurb,
            'wishlistedIds' => auth()->user()?->wishlistItems()->pluck('catalog_item_id')->all() ?? [],
            'options' => $options($filters),
            'filters' => $filters,
            // Options for the inline "add to collection" graded picker on each card.
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
            'seo' => $seo,
            // Language selector shows while browsing a brand's series/sets.
            'tileLanguages' => in_array($mode, ['series', 'sets'], true) && ! empty($filters['product_line'])
                ? $tiles->languagesFor($filters['product_line'])
                : [],
        ];

        // Tile views (brands/series/sets/subsets) show navigation cards instead of
        // dumping every catalog item. A search or a leaf selection drops to cards.
        if ($mode !== 'cards') {
            return Inertia::render('catalog/browse', [
                ...$common,
                'tiles' => $tileData,
                'items' => [],
                'pagination' => ['page' => 1, 'last_page' => 1, 'per_page' => 0, 'total' => 0, 'has_more' => false],
            ]);
        }

        $paginator = $search($filters);

        return Inertia::render('catalog/browse', [
            ...$common,
            'tiles' => [],
            // Merge so each scrolled-in page appends to the list (infinite scroll);
            // a filter change resets it via the request's reset header.
            'items' => Inertia::merge(
                fn () => CatalogItemResource::collection($paginator->items())->resolve(),
            ),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * The deepest drill-down level implied by the filters: a search/subset shows
     * cards; a set shows its subsets; a series shows its sets; a brand shows its
     * series; nothing shows the brands. (series/subsets may collapse — see above.)
     *
     * @param  array<string, mixed>  $filters
     */
    private function browseMode(array $filters): string
    {
        return match (true) {
            ! empty($filters['q']) || ! empty($filters['subset']) => 'cards',
            ! empty($filters['set']) => 'subsets',
            ! empty($filters['series']) => 'sets',
            ! empty($filters['product_line']) => 'series',
            default => 'brands',
        };
    }

    public function show(
        Request $request,
        CatalogItem $catalogItem,
        ShowCatalogItem $show,
        MaybeRefreshEbay $maybeRefresh,
        PriceHistory $history,
    ): Response {
        // Record the view (popularity drives refresh cadence) and, if the eBay
        // data is stale for this item's tier, queue a background refresh. The
        // page renders the current cached value immediately.
        $catalogItem->forceFill([
            'popularity' => $catalogItem->popularity + 1,
            'last_viewed_at' => Carbon::now(),
        ])->save();

        // When stale (>12h), this queues a background refresh and reports true so
        // the page shows an "updating" indicator and polls for the new values.
        $refreshing = $maybeRefresh($catalogItem);

        $model = $show($catalogItem); // has marketValues + relations loaded

        return Inertia::render('catalog/show', [
            // The resource wraps under `data` (consistent with the API + the
            // browse collection); the page reads props.item.data.
            'item' => new CatalogItemResource($model),
            'refreshing' => $refreshing,
            'refreshedAt' => $catalogItem->ebay_refreshed_at?->toIso8601String(),
            'priceHistory' => $history($catalogItem),
            'ownership' => $this->ownership($request, $model),
            'wishlisted' => (bool) $request->user()?->wishlistItems()
                ->where('catalog_item_id', $catalogItem->id)->exists(),
            // Options for the "add to collection" graded picker.
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
            // Sealed-product editor options (admin only, but cheap to ship).
            'sealedTypes' => TcgVertical::SEALED_TYPES,
            'languages' => TcgVertical::LANGUAGES,
            // "More in this set" — other base cards from the same set.
            'moreInSet' => $this->moreInSet($catalogItem),
        ]);
    }

    /**
     * Other cards in the same set sharing this card's rarity (one representative
     * per base card, excluding the card being viewed), for the detail page's
     * horizontal scroller. Falls back to the whole set when the card has no rarity.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function moreInSet(CatalogItem $item): array
    {
        if ($item->set_id === null) {
            return [];
        }

        $rarity = $item->getAttribute('attributes')['rarity'] ?? null;

        $repIds = CatalogItem::query()
            ->where('set_id', $item->set_id)
            ->where('base_key', '!=', $item->base_key)
            ->when($rarity, fn ($q) => $q->where('attributes->rarity', $rarity))
            ->selectRaw('MIN(id) as id')
            ->groupBy('base_key')
            ->limit(24)
            ->pluck('id');

        $cards = CatalogItem::whereIn('id', $repIds)
            ->with('defaultMarketValue')
            ->orderByDesc('popularity')
            ->orderBy('id')
            ->get();

        return CatalogItemResource::collection($cards)->resolve();
    }

    /**
     * The viewer's owned copies of this card (per priced state), or null.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function ownership(Request $request, CatalogItem $model): ?array
    {
        if (! $request->user()) {
            return null;
        }

        $items = CollectionItem::where('catalog_item_id', $model->id)
            ->where('user_id', $request->user()->id)
            ->with(['acquisitionLots', 'gradingCompany'])
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        return $items->map(function (CollectionItem $ci) use ($model) {
            $ci->setRelation('catalogItem', $model); // reuse loaded marketValues
            $unit = $ci->currentUnitValue();
            $market = $unit !== null ? $unit * $ci->quantity : null;
            $cost = $ci->costBasisCents();

            return [
                'state_label' => $ci->stateLabel(),
                'quantity' => $ci->quantity,
                'market_value' => $market,
                'cost_basis' => $cost,
                'unrealized_gain' => $market !== null ? $market - $cost : null,
            ];
        })->all();
    }
}
