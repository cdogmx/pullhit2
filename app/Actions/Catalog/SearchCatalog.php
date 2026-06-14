<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search, filter, sort, and paginate the catalog. The single source of catalog
 * query logic — called by both the web (Inertia) and Api/V1 (JSON) controllers
 * so they behave identically (§2 API-first). Read-only.
 */
class SearchCatalog
{
    /** Sort key => column/expression. */
    protected const SORTS = [
        'number' => 'number',
        'name' => 'name',
        'newest' => 'created_at',
    ];

    /**
     * @param  array<string, mixed>  $filters  normalized by SearchCatalogRequest
     * @return LengthAwarePaginator<CatalogItem>
     */
    public function __invoke(array $filters): LengthAwarePaginator
    {
        $query = CatalogItem::query()->with(['vertical', 'productLine', 'set', 'defaultMarketValue']);

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyFilters($query, $filters);

        if (! empty($filters['group'])) {
            $this->collapseToBaseCards($query);
        }

        $this->applySort($query, $filters['sort'] ?? 'number', $filters['direction'] ?? 'asc');

        $perPage = (int) ($filters['per_page'] ?? 24);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Edition/variant phrases that may appear in a free-text query — these live in
     * `attributes`, not `name`, so we lift them out and match them as facets.
     * Longest phrases first so "reverse holo" wins over "reverse".
     */
    private const SEARCH_FACETS = [
        '1st edition' => ['edition', 'first_edition'],
        'first edition' => ['edition', 'first_edition'],
        'shadowless' => ['edition', 'shadowless'],
        'unlimited' => ['edition', 'unlimited'],
        'reverse holo' => ['variant', 'reverse_holo'],
        'reverse' => ['variant', 'reverse_holo'],
    ];

    /** Filler words people add that aren't in any card/set name. */
    private const STOPWORDS = ['set', 'sets', 'card', 'cards', 'the'];

    /**
     * Free-text search that mirrors how people actually type — name + number
     * ("charizard 4/102"), name + set ("charizard base set"), name + edition
     * ("charizard 1st edition"). We lift edition/variant phrases to facets, then
     * AND the remaining tokens, each matched across name OR set name OR number.
     *
     * @param  Builder<CatalogItem>  $query
     */
    protected function applySearch(Builder $query, ?string $q): void
    {
        if (! $q) {
            return;
        }

        // 1) Lift "1st edition", "shadowless", "reverse", … into edition/variant
        //    facets — they live in attributes, not the stored name.
        $text = ' '.strtolower(trim($q)).' ';
        foreach (self::SEARCH_FACETS as $phrase => [$facet, $value]) {
            if (str_contains($text, " {$phrase} ")) {
                $query->where("attributes->{$facet}", $value);
                $text = str_replace(" {$phrase} ", ' ', $text);
            }
        }

        // 2) Each remaining token must match somewhere (AND across tokens).
        $tokens = array_filter(
            preg_split('/\s+/', trim($text)) ?: [],
            fn (string $t) => $t !== '' && ! in_array($t, self::STOPWORDS, true),
        );

        foreach ($tokens as $token) {
            $query->where(function (Builder $w) use ($token): void {
                $w->where('name', 'like', "%{$token}%")
                    ->orWhereHas('set', fn (Builder $s) => $s->where('name', 'like', "%{$token}%"));

                // A number token, possibly written "4/102" or "025".
                if (preg_match('/\d/', $token)) {
                    $numerator = ltrim(explode('/', $token)[0], '0');
                    $w->orWhere('number', 'like', "%{$token}%")
                        ->orWhere('number', $numerator === '' ? '0' : $numerator);
                }
            });
        }
    }

    /**
     * @param  Builder<CatalogItem>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['vertical'] ?? null, fn (Builder $q, $slug) => $q->whereHas('vertical', fn (Builder $v) => $v->where('slug', $slug)))
            ->when($filters['product_line'] ?? null, fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->when($filters['series'] ?? null, fn (Builder $q, $series) => $q->whereHas('set', fn (Builder $s) => $s->where('series', $series)))
            ->when($filters['set'] ?? null, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            ->when($filters['item_type'] ?? null, fn (Builder $q, $type) => $q->where('item_type', $type))
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', $lang))
            ->when($filters['rarity'] ?? null, fn (Builder $q, $rarity) => $q->where('attributes->rarity', $rarity))
            ->when($filters['variant'] ?? null, fn (Builder $q, $variant) => $q->where('attributes->variant', $variant))
            ->when($filters['edition'] ?? null, fn (Builder $q, $edition) => $q->where('attributes->edition', $edition));

        // `subset` (only ever 'main' in URLs) is a UI sentinel that drops a parent
        // set to its own cards; a child gallery is browsed by its own set slug.
    }

    /**
     * Keep one representative row per base card and attach the printing count.
     *
     * @param  Builder<CatalogItem>  $query
     */
    protected function collapseToBaseCards(Builder $query): void
    {
        $representativeIds = (clone $query)
            ->reorder()
            ->select('base_key')
            ->selectRaw('MIN(id) as rep_id')
            ->groupBy('base_key')
            ->pluck('rep_id');

        $query->whereIn('id', $representativeIds)->withCount('variants');
    }

    /**
     * @param  Builder<CatalogItem>  $query
     */
    protected function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if ($sort === 'set') {
            $query->orderBy(
                Set::select('name')->whereColumn('sets.id', 'catalog_items.set_id'),
                $direction,
            )->orderBy('number');

            return;
        }

        // Sort by the card's headline value (price) or its 30-day % change. Both
        // read the ungraded NM/SEALED market value; cards without one sort last
        // when descending (the usual "highest first" / "biggest gainers" view).
        if ($sort === 'price' || $sort === 'change') {
            $query
                ->orderBy($this->headlineValueSub($sort === 'price' ? 'median' : 'trend_30d'), $direction)
                ->orderBy('name');

            return;
        }

        $column = self::SORTS[$sort] ?? 'number';
        $query->orderBy($column, $direction);

        if ($column !== 'name') {
            $query->orderBy('name'); // stable tiebreak
        }
    }

    /**
     * Subquery selecting one column from a card's headline market value — the
     * ungraded NM (or SEALED) row, same one the lists display. Used to order by
     * price (median) or 30-day % change (trend_30d).
     *
     * @return \Illuminate\Database\Eloquent\Builder<MarketValue>
     */
    protected function headlineValueSub(string $column): \Illuminate\Database\Eloquent\Builder
    {
        return MarketValue::query()
            ->select($column)
            ->whereColumn('market_values.catalog_item_id', 'catalog_items.id')
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->limit(1);
    }
}
