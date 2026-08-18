<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use App\Support\Catalog\LikeTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $grading = $this->gradingFilter($filters);

        $query = CatalogItem::query()->with(['vertical', 'productLine', 'set', 'defaultMarketValue']);

        // Browsing a graded state: load + display that state's value instead of
        // the raw headline (the resource prefers gradedMarketValue when present).
        if ($grading) {
            $query->with(['gradedMarketValue' => fn ($q) => $this->constrainGrading($q, $grading)]);
        }

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyFilters($query, $filters);

        if (! empty($filters['group'])) {
            $this->collapseToBaseCards($query);
        }

        // A search with no explicit sort ranks by relevance; otherwise sort as
        // asked. Inside a single set `q` is a filter over that set's list rather
        // than a search across the catalog, so the binder (number) order stands.
        $sort = $filters['sort'] ?? 'number';
        if (! empty($filters['q']) && empty($filters['set']) && $sort === 'number' && empty($filters['group'])) {
            $this->applyRelevance($query, (string) $filters['q']);
        } else {
            $this->applySort($query, $sort, $filters['direction'] ?? 'asc', $grading);
        }

        $perPage = (int) ($filters['per_page'] ?? 24);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * The active graded-state filter (grader slug + optional grade), or null.
     *
     * @param  array<string, mixed>  $filters
     * @return array{slug: string, grade: ?float}|null
     */
    private function gradingFilter(array $filters): ?array
    {
        $slug = $filters['grading_company'] ?? null;

        if (! $slug) {
            return null;
        }

        return [
            'slug' => (string) $slug,
            'grade' => isset($filters['grade']) && $filters['grade'] !== null ? (float) $filters['grade'] : null,
        ];
    }

    /**
     * Narrow a MarketValue query — or a relation resolving to MarketValue (the
     * gradedMarketValue eager-load, a whereHas closure) — to the requested grader
     * (+ grade). Accepts both since Builder and Relation forward the same methods.
     *
     * @template TQuery of Builder<MarketValue>|\Illuminate\Database\Eloquent\Relations\Relation<MarketValue, *, *>
     *
     * @param  TQuery  $q
     * @param  array{slug: string, grade: ?float}  $grading
     * @return TQuery
     */
    private function constrainGrading($q, array $grading)
    {
        return $q
            ->whereHas('gradingCompany', fn (Builder $c) => $c->where('slug', $grading['slug']))
            ->when($grading['grade'] !== null, fn ($m) => $m->where('grade', $grading['grade']));
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

        ['facets' => $facets, 'tokens' => $tokens] = $this->parseQuery($q);

        // The user typed a query, but nothing usable survived parsing — e.g. it
        // was all LIKE wildcards ("%") or pure filler. Match nothing rather than
        // silently dropping the constraint and returning the whole catalog.
        if ($facets === [] && $tokens === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        // Edition/variant phrases → facets (they live in attributes, not the name).
        // Both are generated, indexed columns, so match those rather than the JSON
        // path — "charizard 1st edition" would otherwise scan the whole catalog.
        foreach ($facets as $facet => $value) {
            $query->where($facet, $value);
        }

        // Each remaining token must match somewhere (AND across tokens).
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

                // A short all-letters token may be the printed prefix of a promo
                // number — "SVP 074", "SWSH 050", "XY 01". Anchored to the start
                // of the number, and only 2–5 letters, so it stays a prefix match
                // and a one-letter token like the "V" in "Charizard V" can't pull
                // in every card whose number merely contains a V.
                //
                // Without this the two halves of "SVP 074" are ANDed and "svp"
                // has to match a name somewhere: it works for SWSH only because
                // that set is literally called "SWSH Black Star Promos", while
                // the SV promos live in "Scarlet & Violet Black Star Promos".
                if (preg_match('/^[a-z]{2,5}$/', $token)) {
                    $w->orWhere('number', 'like', "{$token}%");
                }

                // The printing a card carries lives in attributes.finish and only
                // reaches the reader through the display name — "Erika's Oddish
                // (Love Ball)". The `name` column is just "Erika's Oddish", so
                // without this a search for the pattern printed on the card finds
                // nothing, which is how a whole set of Poke Ball / Dusk Ball /
                // Love Ball printings became invisible.
                $w->orWhere('finish', 'like', "%{$token}%");
            });
        }
    }

    /**
     * Order matched rows by relevance: per token, how well it hits the card name
     * (exact ≫ starts-with ≫ contains), the set name, or the card number. Summed
     * across tokens, with popularity as the tiebreak.
     *
     * @param  Builder<CatalogItem>  $query
     */
    protected function applyRelevance(Builder $query, string $q): void
    {
        $tokens = $this->parseQuery($q)['tokens'];

        if ($tokens === []) {
            $query->orderByDesc('popularity')->orderBy('name');

            return;
        }

        $setName = '(select lower(name) from sets where sets.id = catalog_items.set_id)';
        $parts = [];
        $bindings = [];

        foreach ($tokens as $token) {
            $parts[] = 'case when lower(name) = ? then 100 when lower(name) like ? then 50 when lower(name) like ? then 25 else 0 end';
            array_push($bindings, $token, $token.'%', '%'.$token.'%');

            $parts[] = "case when {$setName} = ? then 30 when {$setName} like ? then 15 else 0 end";
            array_push($bindings, $token, '%'.$token.'%');

            if (preg_match('/\d/', $token)) {
                $numerator = ltrim(explode('/', $token)[0], '0');
                $parts[] = 'case when number = ? then 40 else 0 end';
                $bindings[] = $numerator === '' ? '0' : $numerator;
            }
        }

        // orderByRaw (not a selected column) so pagination's count query — which
        // strips ORDER BY and its bindings — never sees a binding mismatch.
        $query->orderByRaw('('.implode(' + ', $parts).') desc', $bindings)
            ->orderByDesc('popularity')
            ->orderBy('name');
    }

    /**
     * Split a free-text query into edition/variant facets and the remaining
     * search tokens (filler words dropped). Shared by matching + ranking.
     *
     * @return array{facets: array<string, string>, tokens: array<int, string>}
     */
    protected function parseQuery(string $q): array
    {
        $text = ' '.strtolower(trim($q)).' ';

        $facets = [];
        foreach (self::SEARCH_FACETS as $phrase => [$facet, $value]) {
            if (str_contains($text, " {$phrase} ")) {
                $facets[$facet] = $value;
                $text = str_replace(" {$phrase} ", ' ', $text);
            }
        }

        $tokens = array_values(array_filter(
            // Strip LIKE wildcards so a stray "%"/"_" can't turn a token into a
            // match-everything pattern (a full-catalog scan).
            array_map(LikeTerm::clean(...), preg_split('/\s+/', trim($text)) ?: []),
            fn (string $t) => $t !== '' && ! in_array($t, self::STOPWORDS, true),
        ));

        return ['facets' => $facets, 'tokens' => $tokens];
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
            // 'all' (or null) means no item-type constraint; any real type filters.
            ->when(
                ($filters['item_type'] ?? null) && $filters['item_type'] !== 'all',
                fn (Builder $q) => $q->where('item_type', $filters['item_type']),
            )
            // These four are generated, indexed columns over the same JSON the
            // registry validates. Filtering `attributes->rarity` instead compiles
            // to a json_extract expression, which no index can serve — every
            // facet filter was a full table scan.
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', $lang))
            ->when($filters['rarity'] ?? null, fn (Builder $q, $rarity) => $q->where('rarity', $rarity))
            ->when($filters['variant'] ?? null, fn (Builder $q, $variant) => $q->where('variant', $variant))
            ->when($filters['edition'] ?? null, fn (Builder $q, $edition) => $q->where('edition', $edition));

        // Graded state: keep only cards that have a value for the chosen grader
        // (and grade). The matching value is loaded + displayed in __invoke.
        if ($grading = $this->gradingFilter($filters)) {
            $query->whereHas('marketValues', fn (Builder $mv) => $this->constrainGrading($mv, $grading));
        }

        $this->applyOwnership($query, $filters);

        // `subset` (only ever 'main' in URLs) is a UI sentinel that drops a parent
        // set to its own cards; a child gallery is browsed by its own set slug.
    }

    /**
     * Restrict to cards the logged-in user does ("owned") or doesn't ("unowned")
     * already have in a collection. Matches on base_key so owning ANY printing of
     * a card counts (browse can collapse printings to one row), falling back to
     * the exact id when a card has no base_key. A no-op without a user id (guests)
     * or when the filter is "all".
     *
     * @param  Builder<CatalogItem>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyOwnership(Builder $query, array $filters): void
    {
        $owned = $filters['owned'] ?? null;
        $userId = $filters['user_id'] ?? null;

        if (! in_array($owned, ['owned', 'unowned'], true) || empty($userId)) {
            return;
        }

        // Resolve what the user owns ONCE, then ask a plain membership question.
        //
        // This used to be a correlated NOT EXISTS whose predicate OR'd two
        // different columns (owned.base_key = ours OR owned.id = ours). MySQL
        // cannot index an OR across columns, so it re-probed the user's whole
        // collection for every candidate row — 4.9k rows x 735 collection items,
        // each with a primary-key lookup behind it. Browsing a series while
        // filtering to unowned took 21s and timed out. Resolving first turns the
        // same question into two indexed IN lists: 8.5s of counting becomes 0.9s,
        // for one extra 0.2s query, and returns identical rows.
        [$ids, $keys] = $this->ownedKeys((int) $userId);

        if ($ids === []) {
            // Owns nothing at all: "owned" can match nothing, "unowned" everything.
            if ($owned === 'owned') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        // base_key is nullable. "NULL IN (...)" is NULL rather than false, and a
        // NULL inside the negation would silently drop unowned rows — so the
        // base_key branch is guarded to be strictly true-or-false.
        $matches = fn (Builder $q) => $q
            ->whereIn('catalog_items.id', $ids)
            ->when($keys !== [], fn (Builder $w) => $w->orWhere(fn (Builder $b) => $b
                ->whereNotNull('catalog_items.base_key')
                ->whereIn('catalog_items.base_key', $keys)));

        $owned === 'owned' ? $query->where($matches) : $query->whereNot($matches);
    }

    /**
     * The catalog ids in a user's collection, plus the base_keys those ids belong
     * to — so owning any printing of a card counts as owning the card.
     *
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    protected function ownedKeys(int $userId): array
    {
        $rows = DB::table('collection_items as ci')
            ->join('catalog_items as owned', 'owned.id', '=', 'ci.catalog_item_id')
            ->where('ci.user_id', $userId)
            ->distinct()
            ->get(['ci.catalog_item_id as id', 'owned.base_key as base_key']);

        return [
            $rows->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            $rows->pluck('base_key')->filter()->unique()->values()->all(),
        ];
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
     * @param  array{slug: string, grade: ?float}|null  $grading
     */
    protected function applySort(Builder $query, string $sort, string $direction, ?array $grading = null): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if ($sort === 'set') {
            $query->orderBy(
                Set::select('name')->whereColumn('sets.id', 'catalog_items.set_id'),
                $direction,
            );
            $this->orderByNumber($query);

            return;
        }

        // Sort by the set's actual release date (distinct from "newest", which
        // sorts by when the row was added to the catalog). Cards in undated sets
        // sort last when descending (the usual "newest releases first" view).
        if ($sort === 'release') {
            $query->orderBy(
                Set::select('released_at')->whereColumn('sets.id', 'catalog_items.set_id'),
                $direction,
            );
            $this->orderByNumber($query);

            return;
        }

        // Sort by the card's headline value (price) or its 30-day % change. Reads
        // the ungraded NM/SEALED market value — or, when browsing a grade, the
        // matching graded value. Cards without one sort last when descending (the
        // usual "highest first" / "biggest gainers" view).
        if ($sort === 'price' || $sort === 'change') {
            $query
                ->orderBy($this->headlineValueSub($sort === 'price' ? 'median' : 'trend_30d', $grading), $direction)
                ->orderBy('name');

            return;
        }

        $column = self::SORTS[$sort] ?? 'number';

        if ($column === 'number') {
            $this->orderByNumber($query, $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        if ($column !== 'name') {
            $query->orderBy('name'); // stable tiebreak
        }
    }

    /**
     * Order by collector number the way a binder does. `number` is a string, so a
     * plain sort runs 1, 10, 100, 11, 2 — ordering by length first restores the
     * numeric run (1, 2, 10, 100) without giving up the prefixed formats we also
     * store: "SM99" precedes "SM164", and same-length codes ("OP04-008" vs
     * "OP04-038") still fall out in order lexicographically. Numeric-only numbers
     * sort ahead of prefixed promos, which is the order sets print them in.
     *
     * @param  Builder<CatalogItem>  $query
     */
    protected function orderByNumber(Builder $query, string $direction = 'asc'): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $query->orderByRaw("LENGTH(number) {$direction}, number {$direction}");
    }

    /**
     * Subquery selecting one column from a card's headline market value — the
     * ungraded NM (or SEALED) row, same one the lists display. When browsing a
     * grade, it instead reads the matching graded row (the same value shown).
     * Used to order by price (median) or 30-day % change (trend_30d).
     *
     * @param  array{slug: string, grade: ?float}|null  $grading
     * @return Builder<MarketValue>
     */
    protected function headlineValueSub(string $column, ?array $grading = null): Builder
    {
        $sub = MarketValue::query()
            ->select($column)
            ->whereColumn('market_values.catalog_item_id', 'catalog_items.id')
            ->limit(1);

        if ($grading) {
            return $this->constrainGrading($sub, $grading)
                ->orderByDesc('grade')
                ->orderByDesc('median');
        }

        return $sub
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->orderBy('id');
    }
}
