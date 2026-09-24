<?php

namespace App\Support\Lists;

use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The filter and sort a user has applied to a list of their cards.
 *
 * Shared by the collection and the wishlist because they are the same list of
 * the same cards seen two ways, and a filter that behaves differently between
 * them is a bug waiting to be reported. Everything here is read from the query
 * string and written back to it, so a filtered view is a URL: it survives a
 * refresh, the Back button, and being sent to somebody else.
 */
final class ListControls
{
    public const DEFAULT_SORT = 'recent';

    /** Sorts both lists understand. */
    public const SORTS = [
        'recent', 'oldest', 'name', 'set', 'value_desc', 'value_asc',
        // Collection-only in practice — a wishlist has no cost basis to gain
        // against and holds one of each — but harmless there: the metrics
        // resolver simply reports null and they fall to the end.
        'gain_desc', 'gain_asc', 'quantity',
    ];

    /**
     * @param  array<int, string>  $rarities
     */
    private function __construct(
        public readonly array $rarities,
        public readonly string $sort,
        public readonly ?string $q = null,
        public readonly ?string $set = null,
        public readonly ?string $folder = null,
        public readonly bool $forSale = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $raw = $request->query('rarity', []);

        // ?rarity=Common arrives as a string, ?rarity[]=Common as an array.
        // Both are things a person can type or a link can carry.
        $rarities = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) $raw),
            fn (string $v) => $v !== '',
        )));

        $sort = (string) $request->query('sort', self::DEFAULT_SORT);

        $text = fn (string $key) => ($v = trim((string) $request->query($key, ''))) !== '' ? $v : null;

        return new self(
            $rarities,
            // An unknown sort is the default, not an error: these values live in
            // URLs people edit, bookmark and share, and a 500 for a typo in a
            // link is a worse answer than the list they expected.
            in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT,
            $text('q'),
            $text('set'),
            $text('folder'),
            $request->boolean('for_sale'),
        );
    }

    public function isFiltered(): bool
    {
        return $this->rarities !== []
            || $this->q !== null
            || $this->set !== null
            || $this->folder !== null
            || $this->forSale;
    }

    /** @return array<string, mixed> */
    public function queryParams(): array
    {
        return array_filter([
            'rarity' => $this->rarities,
            'sort' => $this->sort === self::DEFAULT_SORT ? null : $this->sort,
            'q' => $this->q,
            'set' => $this->set,
            'folder' => $this->folder,
            'for_sale' => $this->forSale ? 1 : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Narrow a query over items that belong to a catalog item.
     *
     * Filters on the rarity COLUMN, which is indexed and populated for all
     * 66,668 singles that have a rarity — the same values `attributes->rarity`
     * holds, without the unindexable JSON extraction.
     *
     * Takes a relation as readily as a builder: the callers hold
     * `$collection->items()` and `$wishlist->items()`, and forcing those
     * through ->getQuery() at every call site only invites one of them to
     * forget and diverge.
     *
     * @template TQuery of Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, *>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->when($this->rarities !== [], fn ($q) => $q->whereHas(
                'catalogItem',
                fn (Builder $c) => $c->whereIn('rarity', $this->rarities),
            ))
            ->when($this->set !== null, fn ($q) => $q->whereHas(
                'catalogItem.set',
                fn (Builder $s) => $s->where('name', $this->set),
            ))
            // Name, collector number, or the set it came from — the three
            // things somebody types when hunting for a card they own.
            ->when($this->q !== null, fn ($q) => $q->whereHas(
                'catalogItem',
                fn (Builder $c) => $c
                    ->where(fn (Builder $w) => $w
                        ->where('name', 'like', '%'.$this->escapeLike($this->q).'%')
                        ->orWhere('number', 'like', '%'.$this->escapeLike($this->q).'%')
                        ->orWhereHas('set', fn (Builder $s) => $s
                            ->where('name', 'like', '%'.$this->escapeLike($this->q).'%')),
                    ),
            ));
    }

    /**
     * The filters only a collection has: its folders, and what is up for sale.
     *
     * Separate because a wishlist has neither column, and a shared bar means a
     * URL carrying ?folder= can be pasted from one page to the other.
     *
     * @template TQuery of Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, *>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyPortfolio(Builder|Relation $query): Builder|Relation
    {
        return $this->apply($query)
            ->when($this->folder !== null, fn ($q) => $q->where('folder', $this->folder))
            ->when($this->forSale, fn ($q) => $q->where('is_for_sale', true));
    }

    /**
     * A user's search term is a literal, not a pattern.
     *
     * "Pikachu %" should find nothing rather than everything — an unescaped
     * wildcard in a LIKE quietly turns a narrowing search into a widening one.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * Order loaded rows.
     *
     * Sorted in PHP rather than SQL because a card's value is computed, not
     * stored: the collection multiplies a unit value by quantity and the
     * wishlist reads a market value, and neither is a column to ORDER BY.
     * Both lists load in full already, so there is no page to sort across.
     *
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): array{value: ?int, gain: ?int, quantity: int}  $metrics
     * @return Collection<int, TItem>
     */
    public function sort(Collection $items, callable $metrics): Collection
    {
        $valueOf = fn ($i) => $metrics($i)['value'] ?? null;
        $gainOf = fn ($i) => $metrics($i)['gain'] ?? null;

        return match ($this->sort) {
            'oldest' => $items->sortBy('id')->values(),
            'name' => $items->sortBy(fn ($i) => mb_strtolower((string) $i->catalogItem?->name))->values(),
            'quantity' => $items->sortByDesc(fn ($i) => $metrics($i)['quantity'] ?? 0)->values(),
            'set' => $items->sortBy([
                fn ($a, $b) => strcmp(
                    mb_strtolower((string) $a->catalogItem?->set?->name),
                    mb_strtolower((string) $b->catalogItem?->set?->name),
                ),
                // Within a set, collector order — and numerically, so 10 sorts
                // after 9 rather than between 1 and 2.
                fn ($a, $b) => self::numberOf($a->catalogItem) <=> self::numberOf($b->catalogItem),
            ])->values(),

            // A card we cannot value sorts last either way. It is not worth
            // zero — we just don't know — so it does not belong at the top of
            // "cheapest first" pretending to be the best deal.
            'value_desc' => self::byMetric($items, $valueOf, descending: true),
            'value_asc' => self::byMetric($items, $valueOf, descending: false),

            // Same rule for profit and loss: a holding with no cost basis, or
            // no value to measure against it, has no gain — not a gain of zero.
            'gain_desc' => self::byMetric($items, $gainOf, descending: true),
            'gain_asc' => self::byMetric($items, $gainOf, descending: false),

            default => $items->sortByDesc('id')->values(),
        };
    }

    /**
     * Every rarity present in a list, with how many cards carry it.
     *
     * Built from the UNFILTERED list on purpose. Deriving the checkboxes from
     * the filtered result would make them disappear as they were ticked, and
     * there would be no way to untick the last one.
     *
     * The values are not normalised: "C" and "Common" stay apart because they
     * belong to different games — C, UC, R and SR are One Piece's, and no game
     * uses both spellings — so merging them would put a label on a card that
     * isn't printed on it.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, *>  $unfiltered
     * @return array<int, array{value: string, count: int}>
     */
    public static function rarityOptions(Builder|Relation $unfiltered): array
    {
        $ids = $unfiltered->clone()->select('catalog_item_id');

        return CatalogItem::query()
            ->whereIn('id', $ids)
            ->whereNotNull('rarity')
            ->where('rarity', '!=', '')
            ->selectRaw('rarity, count(*) as total')
            ->groupBy('rarity')
            // Commonest first: a collection can hold 26 distinct rarities, and
            // alphabetical order buries the ones worth filtering on.
            ->orderByDesc('total')->orderBy('rarity')
            ->get()
            ->map(fn ($r) => ['value' => (string) $r->rarity, 'count' => (int) $r->total])
            ->all();
    }

    /**
     * Every set present in a list, alphabetically.
     *
     * From the unfiltered list for the same reason the rarities are: the table
     * used to derive this from the rows it had been handed, which was harmless
     * while filtering was client-side and the rows were all of them. Now that
     * the server returns only matches, deriving it from those would leave one
     * set in the dropdown — the one already chosen.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, *>  $unfiltered
     * @return array<int, string>
     */
    public static function setOptions(Builder|Relation $unfiltered): array
    {
        return Set::query()
            ->whereIn('id', CatalogItem::query()
                ->whereIn('id', $unfiltered->clone()->select('catalog_item_id'))
                ->select('set_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rarity' => $this->rarities,
            'sort' => $this->sort,
            'q' => $this->q,
            'set' => $this->set,
            'folder' => $this->folder,
            'for_sale' => $this->forSale,
        ];
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): ?int  $metric
     * @return Collection<int, TItem>
     */
    private static function byMetric(Collection $items, callable $metric, bool $descending): Collection
    {
        [$valued, $unvalued] = $items->partition(fn ($i) => $metric($i) !== null);

        $sorted = $descending
            ? $valued->sortByDesc($metric)
            : $valued->sortBy($metric);

        return $sorted->values()->concat($unvalued->values())->values();
    }

    /** A collector number as a number, for ordering inside a set. */
    private static function numberOf(?CatalogItem $item): int
    {
        preg_match('/\d+/', (string) $item?->number, $m);

        return isset($m[0]) ? (int) $m[0] : PHP_INT_MAX;
    }
}
