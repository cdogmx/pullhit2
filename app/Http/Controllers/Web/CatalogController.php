<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\BrowseTiles;
use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\SearchCatalog;
use App\Actions\Catalog\ShowCatalogItem;
use App\Actions\Catalog\SuggestSearch;
use App\Actions\Valuation\MaybeRefreshEbay;
use App\Actions\Valuation\MaybeRefreshPricecharting;
use App\Actions\Valuation\PriceHistory;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\GradingCompany;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\CrossLanguageMatcher;
use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Http\RedirectResponse;
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
            // Weekly-generated top-cards collage; cache-bust on its refresh time.
            'image' => $setModel->og_image_path
                ? $setModel->og_image_path.'?v='.($setModel->og_image_at?->timestamp ?? 0)
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{title: string, heading: string}|null  $seo
     */
    private function renderBrowse(array $filters, SearchCatalog $search, CatalogFilterOptions $options, ?array $seo = null): Response
    {
        // Browse/search leads with individual cards. A missing item_type defaults
        // to singles; the UI's "All types" option sends 'all' to opt back in.
        if (($filters['item_type'] ?? null) === null) {
            $filters['item_type'] = ItemType::Single->value;
        }

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

        // Server-rendered SEO/share meta for the brand/set landing pages, with a
        // search/landing fallback so every browse view has accurate meta.
        $meta = $seo ? array_filter([
            'title' => $seo['title'].' | '.config('app.name'),
            'description' => $blurb
                ?: "Browse {$seo['heading']} cards with confidence-scored market prices, sets, and values on ".config('app.name').'.',
            // Generated set collage as the OG/Twitter image, when available.
            'image' => $seo['image'] ?? null,
            'image_alt' => isset($seo['image']) ? "{$seo['heading']} top cards" : null,
        ], fn ($v) => $v !== null) : $this->browseFallbackMeta($filters);

        // Share the FULL URL (with the search + filters) so a shared browse link
        // reproduces the exact view — unlike the path-only canonical elsewhere,
        // people share their searches. Drives canonical + og:url (Blade) and the
        // client-side share-URL sync on navigation.
        $meta['url'] = request()->fullUrl();

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
            'meta' => $meta,
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

        // On a zero-result search (usually a typo), find the closest card/set/brand
        // name by edit distance. If searching THAT returns results, auto-correct
        // and show them ("Showing results for X") — unless ?exact pins the query;
        // otherwise fall back to a "did you mean" suggestion on the empty page.
        $q = trim((string) ($filters['q'] ?? ''));
        $didYouMean = null;
        $autoCorrectedTo = null;

        if ($q !== '' && $paginator->total() === 0) {
            $correction = app(SuggestSearch::class)->didYouMean($q);

            if ($correction !== null && ! request()->boolean('exact')) {
                $corrected = $search([...$filters, 'q' => $correction]);

                if ($corrected->total() > 0) {
                    $paginator = $corrected;
                    $autoCorrectedTo = $correction;
                } else {
                    $didYouMean = $correction;
                }
            } else {
                $didYouMean = $correction;
            }
        }

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
            'autoCorrectedTo' => $autoCorrectedTo,
            // The original query (server-authoritative) — used by the banner and
            // the empty-state so they don't depend on the client's filter state.
            'searchedQuery' => $q !== '' ? $q : null,
            'didYouMean' => $didYouMean,
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
            ! empty($filters['all']) || ! empty($filters['q']) || ! empty($filters['subset']) => 'cards',
            ! empty($filters['set']) => 'subsets',
            ! empty($filters['series']) => 'sets',
            ! empty($filters['product_line']) => 'series',
            default => 'brands',
        };
    }

    /**
     * Legacy /catalog/{id} — 301 to the canonical /{brand}/{set}/{card} URL so
     * old links and shares consolidate. Cards without a slug path still render.
     */
    public function show(
        Request $request,
        CatalogItem $catalogItem,
        ShowCatalogItem $show,
        MaybeRefreshEbay $maybeRefresh,
        PriceHistory $history,
    ): Response|RedirectResponse {
        $catalogItem->loadMissing('productLine', 'set');

        if ($path = $catalogItem->path()) {
            return redirect()->to($path, 301);
        }

        return $this->renderShow($request, $catalogItem, $show, $maybeRefresh, $history);
    }

    /** Canonical card page at /{brand}/{set}/{card-slug}. */
    public function showBySlug(
        Request $request,
        string $productLine,
        string $set,
        string $card,
        ShowCatalogItem $show,
        MaybeRefreshEbay $maybeRefresh,
        PriceHistory $history,
    ): Response {
        $line = ProductLine::where('slug', $productLine)->firstOrFail();
        $setModel = Set::where('slug', $set)->where('product_line_id', $line->id)->firstOrFail();
        $item = CatalogItem::where('set_id', $setModel->id)->where('slug', $card)->firstOrFail();

        return $this->renderShow($request, $item, $show, $maybeRefresh, $history);
    }

    private function renderShow(
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

        // Lazily pull PriceCharting data (completed sales + long-term monthly
        // history) once per sealed card — fire-and-forget; appears on next load.
        app(MaybeRefreshPricecharting::class)($catalogItem);

        $model = $show($catalogItem); // has marketValues + relations loaded

        // The headline state the page leads with (NM/SEALED first, else the first
        // priced state) — render its history so the chart's initial series matches
        // its default selector option, even on graded-only cards.
        $headlineState = $model->marketValues
            ->first(fn ($v) => in_array($v->state_key, ['NM', 'SEALED'], true))
            ?? $model->marketValues->first();

        return Inertia::render('catalog/show', [
            // Server-rendered share + SEO meta (read by the Blade root for OG /
            // Twitter / JSON-LD before any JS runs).
            'meta' => $this->shareMeta($model),
            // The resource wraps under `data` (consistent with the API + the
            // browse collection); the page reads props.item.data.
            'item' => new CatalogItemResource($model),
            'refreshing' => $refreshing,
            'refreshedAt' => $catalogItem->ebay_refreshed_at?->toIso8601String(),
            'priceHistory' => $history($catalogItem, 365, $headlineState?->state_key),
            // Long-term monthly series from PriceCharting (older than our sold
            // data), keyed by grade tier — a separate multi-year line the chart
            // picks per selected state. Empty until synced.
            'priceHistoryLong' => $model->longTermHistoryTiers(),
            // The most recent REAL sold comp per priced state — the headline
            // trust signal ("last sold $X, Yd ago"), so it tracks the chart's
            // state dropdown. Keyed by state_key; a state with no sale is absent.
            'lastSales' => $catalogItem->lastSalesByState(),
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
            // The same card in other languages (e.g. the Japanese printing).
            'otherLanguages' => $this->otherLanguages($catalogItem),
            // "Where to buy" — live retailer offers from the deals tracker (the
            // single source; replaces the old per-item retailer_links JSON).
            'whereToBuy' => $this->whereToBuy($catalogItem),
        ]);
    }

    /**
     * Live "where to buy" offers for a card, sourced from the deals tracker
     * (App\Models\TrackedProduct + RetailerLink) — the same data behind /deals.
     * Active links only; in-stock first, then cheapest. Prices are integer cents.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function whereToBuy(CatalogItem $item): array
    {
        $links = $item->trackedProducts()
            ->where('is_active', true)
            ->with(['links' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->flatMap(fn ($product) => $product->links)
            // In-stock first (0 < 1), then cheapest known price (unknown last).
            ->sortBy(fn ($link) => [
                $link->last_in_stock ? 0 : 1,
                $link->last_price ?? PHP_INT_MAX,
            ]);

        return $links->map(fn ($link) => [
            'retailer' => $link->retailer->label(),
            'url' => $link->url,
            'price_cents' => $link->last_price,
            'in_stock' => (bool) $link->last_in_stock,
            'checked_at' => $link->last_checked_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The same card in other languages — matched within the product line by name
     * + collector number (+ variant, so a base printing pairs with a base, not an
     * alt art), differing only in language. Lets a card page cross-link its e.g.
     * Japanese printing. Empty when there's no equivalent (common for games whose
     * languages don't share a numbering scheme).
     *
     * @return array<int, array{language: string, name: string, set: ?string, url: string}>
     */
    protected function otherLanguages(CatalogItem $item): array
    {
        return app(CrossLanguageMatcher::class)->forItem($item);
    }

    /**
     * Card-specific share + SEO meta: a descriptive title, a description that
     * includes the current market value when known, the card image as the OG /
     * Twitter image (so a shared link previews the actual card), and Product
     * JSON-LD for search rich results.
     *
     * @return array<string, mixed>
     */
    /**
     * Meta for browse views without a brand/set SEO block — a search-results page
     * (reflects the query) or the generic browse landing.
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, description: string}
     */
    private function browseFallbackMeta(array $filters): array
    {
        $app = config('app.name');
        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            return [
                'title' => "“{$q}” — card search results | {$app}",
                'description' => "Search results for “{$q}” across Pokémon, One Piece, Disney Lorcana and more — with confidence-scored market prices on {$app}.",
            ];
        }

        return [
            'title' => "Browse trading cards — prices, sets & values | {$app}",
            'description' => "Browse Pokémon, One Piece, Disney Lorcana and Cyberpunk singles and sealed products with confidence-scored market prices on {$app}.",
        ];
    }

    protected function shareMeta(CatalogItem $item): array
    {
        $name = $item->display_name ?: $item->name;
        $set = $item->set?->name;
        $brand = $item->productLine?->name ?? $set;
        $where = trim(implode(' ', array_filter([$set, $item->number ? "#{$item->number}" : null])));
        $image = $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null);
        $value = $item->defaultMarketValue?->median;
        $price = $value !== null ? '$'.number_format($value / 100, 2) : null;

        // "Card Name #123 - Set - Brand" (each part only when present).
        $title = implode(' - ', array_filter([
            $name.($item->number ? " #{$item->number}" : ''),
            $set,
            $item->productLine?->name,
        ]));
        $description = $price
            ? "{$name}".($where !== '' ? " ({$where})" : '')." market value: {$price}. Confidence-scored prices from real sales on ".config('app.name').'.'
            : "Track the market value of {$name}".($where !== '' ? " ({$where})" : '').' with confidence-scored prices on '.config('app.name').'.';

        $meta = [
            'title' => $title,
            'description' => $description,
            'og_type' => 'product',
        ];

        if ($image) {
            $meta['image'] = $image;
            $meta['image_alt'] = $name;
        }

        $meta['jsonld'] = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'image' => $image,
            'category' => 'Trading Cards',
            'sku' => (string) $item->id,
            'brand' => $brand ? ['@type' => 'Brand', 'name' => $brand] : null,
            // The market value as a single Offer — accurate for a price guide and
            // what surfaces price in search results.
            'offers' => $value !== null ? [
                '@type' => 'Offer',
                'price' => number_format($value / 100, 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => url()->current(),
            ] : null,
        ], fn ($v) => $v !== null);

        return $meta;
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
            ->with(['defaultMarketValue', 'set', 'productLine'])
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
