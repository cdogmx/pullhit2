<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Database\Eloquent\Builder;

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
        return [
            'verticals' => Vertical::orderBy('name')->get(['slug', 'name']),
            'product_lines' => ProductLine::query()
                ->when($filters['vertical'] ?? null, fn (Builder $q, $slug) => $q->whereHas('vertical', fn (Builder $v) => $v->where('slug', $slug)))
                ->orderBy('name')
                ->get(['slug', 'name']),
            'sets' => Set::query()
                ->when($filters['product_line'] ?? null, fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
                ->orderByDesc('released_at')
                ->get(['slug', 'name', 'code']),
            'item_types' => array_map(fn (ItemType $t) => $t->value, ItemType::cases()),
            'languages' => CatalogItem::query()
                ->whereNotNull('language')
                ->distinct()
                ->orderBy('language')
                ->pluck('language'),
            'rarities' => $this->raritiesPresent($filters),
            'variants' => $this->variantOptions(),
            'editions' => $this->editionsPresent($filters),
        ];
    }

    /**
     * Distinct editions present in the (optionally set-scoped) catalog, so the
     * filter only offers Unlimited/Shadowless/1st Edition where they exist.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function editionsPresent(array $filters): array
    {
        return CatalogItem::query()
            ->where('item_type', ItemType::Single->value)
            ->when($filters['set'] ?? null, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            ->whereNotNull('attributes->edition')
            ->get(['attributes'])
            ->pluck('attributes.edition')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Distinct rarities present in the (optionally set-scoped) catalog. Read via
     * the cast attribute to avoid driver-specific JSON-unquoting.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function raritiesPresent(array $filters): array
    {
        return CatalogItem::query()
            ->where('item_type', ItemType::Single->value)
            ->when($filters['set'] ?? null, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            ->get(['attributes'])
            ->pluck('attributes.rarity')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
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
