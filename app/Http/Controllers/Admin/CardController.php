<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\UpdateCatalogItem;
use App\Actions\Valuation\IngestEbaySoldComps;
use App\Actions\Valuation\IngestPricechartingComps;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCardRequest;
use App\Http\Requests\Admin\UpdateCardRequest;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\GradingCompany;
use App\Models\Set;
use App\Support\Catalog\StampMatcher;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\SoldCompClassifier;
use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin card editor — search the catalog and fix individual cards.
 */
class CardController extends Controller
{
    public function index(Request $request, CatalogFilterOptions $options): Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'set' => (string) $request->query('set', ''),
            'rarity' => (string) $request->query('rarity', ''),
            'variant' => (string) $request->query('variant', ''),
            'language' => (string) $request->query('language', ''),
            'sort' => (string) $request->query('sort', 'name'),
        ];

        $query = CatalogItem::query()
            ->with('set')
            ->when($f['q'] !== '', fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->where('name', 'like', "%{$f['q']}%")->orWhere('number', 'like', "%{$f['q']}%"),
            ))
            ->when($f['set'] !== '', fn (Builder $q) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $f['set'])))
            ->when($f['rarity'] !== '', fn (Builder $q) => $q->where('attributes->rarity', $f['rarity']))
            ->when($f['variant'] !== '', fn (Builder $q) => $q->where('attributes->variant', $f['variant']))
            ->when($f['language'] !== '', fn (Builder $q) => $q->where('language', $f['language']));

        $this->sort($query, $f['sort']);

        $paginator = $query->paginate(30)->withQueryString();

        // Rarities narrow to the selected set; sets/variants/languages are global.
        $o = $options(['set' => $f['set']]);

        return Inertia::render('admin/cards', [
            'items' => collect($paginator->items())->map(fn (CatalogItem $i) => $this->row($i)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'options' => [
                'sets' => $o['sets'],
                'rarities' => $o['rarities'],
                'variants' => $o['variants'],
                'languages' => $o['languages'],
            ],
            // Options for the "New card" form: every set to attach to, plus the
            // full TCG language + variant vocabularies (not just what's present).
            'createOptions' => [
                // Carry brand/series/language/code so the set picker can show and
                // search on them (many sets share a name across games/languages).
                'sets' => Set::with('productLine:id,name')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'language', 'series', 'product_line_id'])
                    ->map(fn (Set $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'brand' => $s->productLine?->name,
                        'series' => $s->series,
                        'language' => $s->language,
                        'code' => $s->code,
                    ]),
                'languages' => TcgVertical::LANGUAGES,
                'variants' => $o['variants'] ?: ['normal', 'holo', 'reverse_holo'],
            ],
            'filters' => $f,
        ]);
    }

    public function store(StoreCardRequest $request, CreateCatalogItem $create): RedirectResponse
    {
        $data = $request->validated();

        $set = Set::with('productLine.vertical')->findOrFail($data['set_id']);

        $attributes = array_filter([
            'language' => $data['language'],
            'variant' => $data['variant'],
            'rarity' => $data['rarity'] ?? null,
            // Normalise the typed stamp to a storage key ("EB Games" → "eb_games")
            // so casing/spacing can't split one printing into two catalog items.
            'stamp' => ! empty($data['stamp']) ? (new StampMatcher)->canonical($data['stamp']) : null,
            'illustrator' => $data['illustrator'] ?? null,
            'hp' => isset($data['hp']) && $data['hp'] !== '' ? (int) $data['hp'] : null,
            'type' => $data['type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $create(
            vertical: $set->productLine->vertical,
            productLine: $set->productLine,
            set: $set,
            itemType: ItemType::Single,
            name: $data['name'],
            number: $data['number'] ?? null,
            attributes: $attributes,
            externalIds: [],
            primaryImagePath: $data['primary_image_path'] ?? null,
        );

        return back()->with('success', 'Card created.');
    }

    /** @param  Builder<CatalogItem>  $query */
    protected function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'views' => $query->orderByDesc('popularity')->orderBy('name'),
            'updated' => $query->orderByDesc('updated_at'),
            'number' => $query->orderByRaw('CAST(number AS UNSIGNED)')->orderBy('number'),
            default => $query->orderBy('name'),
        };
    }

    public function update(UpdateCardRequest $request, CatalogItem $catalogItem, UpdateCatalogItem $update): RedirectResponse
    {
        $update($catalogItem, $request->validated());

        return back()->with('success', 'Card updated.');
    }

    /**
     * Admin "Get values" — force a synchronous eBay sold-comp pull for this card,
     * bypassing the freshness/rarity guards (an admin asked explicitly). Runs
     * inline so the result is immediate (no queue worker needed); still honours
     * the shared daily Oxylabs cap. Returns the number of comps ingested.
     */
    public function refresh(CatalogItem $catalogItem, IngestEbaySoldComps $ingest, IngestPricechartingComps $pcIngest): JsonResponse
    {
        if (! config('valuation.ebay.enabled')) {
            return response()->json(['ok' => false, 'message' => 'eBay refresh is disabled.'], 422);
        }

        $key = 'ebay:daily:'.Carbon::now()->toDateString();
        Cache::add($key, 0, Carbon::now()->endOfDay());

        if ((int) Cache::get($key, 0) >= (int) config('valuation.ebay.daily_cap')) {
            return response()->json(['ok' => false, 'message' => 'Daily eBay request cap reached.'], 429);
        }

        Cache::increment($key);
        $ingested = $ingest($catalogItem);

        // Cards also refresh PriceCharting — it's the source of the completed-sales
        // blend AND the long-term monthly history line (per grade tier for
        // singles). Force it (bypass the on-view TTL) under PC's own daily cap.
        $this->refreshPricecharting($catalogItem, $pcIngest);

        return response()->json([
            'ok' => true,
            'ingested' => $ingested,
            // Fresh long-term series (per grade tier) so the card page updates its
            // history line live.
            'price_history_long' => $catalogItem->longTermHistoryTiers(),
        ]);
    }

    /**
     * Admin comp-preview: run the LIVE eBay sold search for a card and return
     * every raw candidate tagged with the classifier's verdict (would-ingest vs
     * would-reject + the exact reason) plus the resolved priced state. A read-only
     * window into precisely what a refresh would do — nothing is stored. Costs one
     * Oxylabs call, so it honours the same daily cap as a refresh.
     */
    public function compPreview(
        CatalogItem $catalogItem,
        EbaySoldSource $source,
        SoldCompClassifier $classifier,
    ): JsonResponse {
        if (! config('valuation.ebay.enabled')) {
            return response()->json(['ok' => false, 'message' => 'eBay lookups are disabled.'], 422);
        }

        $key = 'ebay:daily:'.Carbon::now()->toDateString();
        Cache::add($key, 0, Carbon::now()->endOfDay());

        if ((int) Cache::get($key, 0) >= (int) config('valuation.ebay.daily_cap')) {
            return response()->json(['ok' => false, 'message' => 'Daily eBay request cap reached.'], 429);
        }

        Cache::increment($key);

        // Same anchor the ingest uses: the raw NM (or SEALED) median.
        $anchor = (int) ($catalogItem->marketValues()
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->value('median') ?? 0);
        $companyIds = GradingCompany::pluck('id', 'slug')->all();

        $candidates = array_map(function ($c) use ($catalogItem, $anchor, $companyIds, $classifier) {
            return [
                'title' => $c->title,
                'price' => $c->priceCents,
                'sold_at' => $c->soldAt?->toIso8601String(),
                'seller' => $c->seller,
                'url' => $c->url,
                'image_url' => $c->imageUrl,
                ...$classifier->diagnose($c, $catalogItem, $anchor, $companyIds),
            ];
        }, $source->fetch($catalogItem));

        return response()->json([
            'ok' => true,
            'query' => $source->searchQuery($catalogItem),
            'url' => $source->soldSearchUrl($catalogItem),
            'anchor' => $anchor,
            'ingested' => count(array_filter($candidates, fn ($c) => $c['verdict'] === 'ingest')),
            'candidates' => $candidates,
        ]);
    }

    private function refreshPricecharting(CatalogItem $catalogItem, IngestPricechartingComps $pcIngest): void
    {
        if (! in_array($catalogItem->item_type, [ItemType::Single, ItemType::Sealed], true)
            || ! config('valuation.pricecharting.enabled', true)) {
            return;
        }

        $key = 'pricecharting:daily:'.Carbon::now()->toDateString();
        Cache::add($key, 0, Carbon::now()->endOfDay());

        if ((int) Cache::get($key, 0) >= (int) config('valuation.pricecharting.daily_cap')) {
            return;
        }

        Cache::increment($key);

        try {
            $pcIngest($catalogItem);
        } catch (\Throwable $e) {
            report($e); // keep existing data; eBay refresh still succeeded
        }
    }

    public function destroy(CatalogItem $catalogItem): RedirectResponse
    {
        $catalogItem->marketValues()->delete();
        $catalogItem->saleObservations()->delete();
        CollectionItem::where('catalog_item_id', $catalogItem->id)->delete();
        $catalogItem->delete();

        return back()->with('success', 'Card deleted.');
    }

    /** @return array<string, mixed> */
    protected function row(CatalogItem $item): array
    {
        $a = $item->attributes ?? [];

        return [
            'id' => $item->id,
            'name' => $item->name,
            'number' => $item->number,
            'language' => $item->language,
            'set' => $item->set?->name,
            'image_url' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'primary_image_path' => $item->primary_image_path,
            'rarity' => $a['rarity'] ?? null,
            'variant' => $a['variant'] ?? null,
            'illustrator' => $a['illustrator'] ?? null,
            'hp' => $a['hp'] ?? null,
            'type' => $a['type'] ?? null,
            'views' => (int) $item->popularity,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
