<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
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
     * @param  Builder<CatalogItem>  $query
     */
    protected function applySearch(Builder $query, ?string $q): void
    {
        if (! $q) {
            return;
        }

        $query->where(function (Builder $w) use ($q): void {
            $w->where('name', 'like', "%{$q}%")
                ->orWhere('number', 'like', "%{$q}%");
        });
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
            ->when($filters['set'] ?? null, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            ->when($filters['item_type'] ?? null, fn (Builder $q, $type) => $q->where('item_type', $type))
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', $lang))
            ->when($filters['rarity'] ?? null, fn (Builder $q, $rarity) => $q->where('attributes->rarity', $rarity))
            ->when($filters['variant'] ?? null, fn (Builder $q, $variant) => $q->where('attributes->variant', $variant));
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

        $column = self::SORTS[$sort] ?? 'number';
        $query->orderBy($column, $direction);

        if ($column !== 'name') {
            $query->orderBy('name'); // stable tiebreak
        }
    }
}
