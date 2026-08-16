<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the facet option lists for the browse filters. Verticals/lines/sets come
 * from the catalog; variants come from the Vertical registry (so the UI never
 * hardcodes per-vertical facets — §3). Option lists narrow to the current
 * vertical/line/set selection where it makes sense.
 */
class CatalogFilterOptions
{
    public function __construct(
        protected VerticalRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function __invoke(array $filters = []): array
    {
        // Which values a facet actually takes in the current vertical/line/set
        // scope, so each filter offers only what exists (a game with only
        // `normal` printings shows no variant filter; a set with no editions
        // shows no edition filter).
        //
        // One indexed DISTINCT per facet. This used to load every in-scope card's
        // `attributes` JSON into PHP and reduce it there — on the browse landing
        // page that hydrated 67,660 rows and took over five seconds, to produce
        // three short lists. rarity/variant/edition are indexed columns now, so
        // the database can answer straight from the index.
        $present = fn (string $column) => $this->applyScope(
            CatalogItem::query()->where('item_type', ItemType::Single->value),
            $filters,
        )->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->all();

        return [
            'verticals' => Vertical::orderBy('name')->get(['slug', 'name']),
            'product_lines' => ProductLine::query()
                ->when($filters['vertical'] ?? null, fn (Builder $q, $slug) => $q->whereHas('vertical', fn (Builder $v) => $v->where('slug', $slug)))
                ->orderBy('name')
                ->get(['slug', 'name']),
            // The brand's series (eras), newest first — the middle rung of the
            // brand → series → set scope pickers. Only meaningful inside a brand.
            'series' => $this->series($filters),
            'sets' => Set::query()
                ->when($filters['product_line'] ?? null, fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
                ->when($filters['series'] ?? null, fn (Builder $q, $series) => $q->where('series', $series))
                ->orderByDesc('released_at')
                ->get(['slug', 'name', 'code', 'language']),
            'item_types' => array_map(fn (ItemType $t) => $t->value, ItemType::cases()),
            'languages' => $this->applyScope(
                CatalogItem::query()->whereNotNull('language'), $filters,
            )->distinct()->orderBy('language')->pluck('language')->all(),
            'rarities' => $present('rarity'),
            'variants' => $this->meaningfulVariants($present('variant')),
            'editions' => $present('edition'),
            // Graders that have graded values in scope, and the grades available
            // (narrowed to the selected grader when one is chosen).
            'grading_companies' => $this->gradingCompanies($filters),
            'grades' => $this->grades($filters),
        ];
    }

    /**
     * The distinct series (eras) of a brand's sets, newest first — mirroring the
     * order of the series browse tiles. Empty outside a brand: series names are
     * only unique within one, so a cross-brand list would be ambiguous.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function series(array $filters): array
    {
        if (empty($filters['product_line'])) {
            return [];
        }

        return Set::query()
            ->whereHas('productLine', fn (Builder $p) => $p->where('slug', $filters['product_line']))
            ->whereNotNull('series')
            ->groupBy('series')
            ->selectRaw('series, max(released_at) as newest')
            ->orderByRaw('max(released_at) desc')
            ->pluck('series')
            ->all();
    }

    /**
     * Grading companies that have at least one graded value within the current
     * scope — so the grader filter only offers graders that would return cards.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, GradingCompany>
     */
    protected function gradingCompanies(array $filters)
    {
        $ids = MarketValue::query()
            ->whereNotNull('grading_company_id')
            ->when(
                $this->isScoped($filters),
                fn (Builder $q) => $q->whereHas('catalogItem', fn (Builder $c) => $this->applyScope($c, $filters)),
            )
            ->distinct()
            ->pluck('grading_company_id');

        return GradingCompany::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['slug', 'name']);
    }

    /**
     * Distinct grades present in scope, highest first (10 → 1). Narrowed to the
     * selected grader so, e.g., BGS's half-grades don't appear under PSA.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, float>
     */
    protected function grades(array $filters): array
    {
        return MarketValue::query()
            ->whereNotNull('grade')
            ->when(
                $filters['grading_company'] ?? null,
                fn (Builder $q, $slug) => $q->whereHas('gradingCompany', fn (Builder $c) => $c->where('slug', $slug)),
            )
            ->when(
                $this->isScoped($filters),
                fn (Builder $q) => $q->whereHas('catalogItem', fn (Builder $c) => $this->applyScope($c, $filters)),
            )
            ->distinct()
            ->orderByDesc('grade')
            ->pluck('grade')
            ->map(fn ($g) => (float) $g)
            ->all();
    }

    /**
     * Narrow a catalog query to the current vertical / product line / series /
     * set selection — the scope every value facet's options are drawn from.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyScope(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['vertical'] ?? null, fn (Builder $q, $slug) => $q->whereHas('vertical', fn (Builder $v) => $v->where('slug', $slug)))
            ->when($filters['product_line'] ?? null, fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->when($filters['series'] ?? null, fn (Builder $q, $series) => $q->whereHas('set', fn (Builder $s) => $s->where('series', $series)))
            ->when($filters['set'] ?? null, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)));
    }

    /**
     * Whether any scope filter is set. With none, `applyScope` constrains
     * nothing, and wrapping a market_values lookup in "exists (every catalog
     * item)" makes the planner walk the join for a question whose answer is just
     * the distinct values of an indexed column.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function isScoped(array $filters): bool
    {
        foreach (['vertical', 'product_line', 'series', 'set'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Present variants ordered by the registry's canonical order. A scope whose
     * only printing is `normal` has nothing to filter on, so the facet is dropped.
     *
     * @param  array<int, string>  $present
     * @return array<int, string>
     */
    protected function meaningfulVariants(array $present): array
    {
        if ($present === [] || $present === ['normal']) {
            return [];
        }

        $order = $this->variantOptions();
        usort($present, fn ($a, $b) => $this->orderIndex($a, $order) <=> $this->orderIndex($b, $order));

        return $present;
    }

    /** @param  array<int, string>  $order */
    private function orderIndex(string $value, array $order): int
    {
        $i = array_search($value, $order, true);

        return $i === false ? PHP_INT_MAX : $i;
    }

    /**
     * @return array<int, string>
     */
    protected function variantOptions(): array
    {
        if (! $this->registry->has('tcg')) {
            return [];
        }

        foreach ($this->registry->get('tcg')->attributesFor(ItemType::Single->value) as $definition) {
            if ($definition->key === 'variant') {
                return $definition->options ?? [];
            }
        }

        return [];
    }
}
