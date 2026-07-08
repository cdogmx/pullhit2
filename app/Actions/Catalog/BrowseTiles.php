<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\SeriesMeta;
use App\Models\Set;
use App\Support\Catalog\Subsets;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Smart browse" navigation tiles. Drills brand → series → set → subset → card
 * instead of dumping the whole catalog up front; each level only appears when it
 * adds a choice (a brand with one series skips to sets; a set with no named
 * subsets skips to cards — resolved by the controller on tile count).
 */
class BrowseTiles
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(string $mode, array $filters): array
    {
        return match ($mode) {
            'series' => $this->series($filters),
            'sets' => $this->sets($filters),
            'subsets' => $this->subsets($filters),
            default => $this->brands($filters),
        };
    }

    /**
     * Languages present across a product line's sets, for the set-mode selector.
     *
     * @return array<int, string>
     */
    public function languagesFor(string $productLineSlug): array
    {
        return Set::query()
            ->whereHas('productLine', fn (Builder $q) => $q->where('slug', $productLineSlug))
            ->whereNotNull('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language')
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function brands(array $filters): array
    {
        return ProductLine::query()
            ->when($filters['vertical'] ?? null, fn (Builder $q, $slug) => $q->whereHas('vertical', fn (Builder $v) => $v->where('slug', $slug)))
            ->addSelect(['thumb' => $this->thumb('product_line_id', 'product_lines.id')])
            ->addSelect(['item_count' => CatalogItem::query()->selectRaw('count(*)')->whereColumn('product_line_id', 'product_lines.id')])
            ->orderBy('name')
            ->get()
            ->map(fn (ProductLine $line) => [
                'kind' => 'brand',
                'slug' => $line->slug,
                'name' => $line->name,
                'count' => (int) $line->getAttribute('item_count'),
                // Admin-set brand logo wins; otherwise a representative card.
                'thumb' => $line->logo_path ?: $line->getAttribute('thumb'),
            ])
            ->filter(fn (array $t) => $t['count'] > 0)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function sets(array $filters): array
    {
        $line = ProductLine::where('slug', $filters['product_line'] ?? null)->first();

        if (! $line) {
            return [];
        }

        $sets = Set::query()
            ->where('product_line_id', $line->id)
            ->when($filters['series'] ?? null, fn (Builder $q, $series) => $q->where('series', $series))
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', $lang))
            ->addSelect(['thumb' => $this->thumb('set_id', 'sets.id')])
            ->addSelect(['item_count' => CatalogItem::query()->selectRaw('count(*)')->whereColumn('set_id', 'sets.id')])
            ->orderByDesc('released_at')
            ->orderBy('name')
            ->get();

        // Hide gallery child sets (e.g. "… Trainer Gallery") whose parent is also
        // present — they nest under the parent as subsets instead.
        $names = $sets->pluck('name')->all();

        return $sets
            ->reject(function (Set $set) use ($names) {
                [$parent] = Subsets::split($set->name);

                return $parent !== null && in_array($parent, $names, true);
            })
            ->map(fn (Set $set) => [
                'kind' => 'set',
                'slug' => $set->slug,
                'name' => $set->name,
                'code' => $set->code,
                'language' => $set->language,
                'released_at' => $set->released_at?->toDateString(),
                'count' => (int) $set->getAttribute('item_count'),
                // Admin-set set logo wins; otherwise a representative card.
                'thumb' => $set->logo_path ?: $set->getAttribute('thumb'),
            ])
            ->filter(fn (array $t) => $t['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * Series tiles for a brand — the era grouping (Scarlet & Violet, etc.),
     * newest first, with a representative card image.
     *
     * @return array<int, array<string, mixed>>
     */
    private function series(array $filters): array
    {
        $line = ProductLine::where('slug', $filters['product_line'] ?? null)->first();

        if (! $line) {
            return [];
        }

        $lang = $filters['language'] ?? null;

        // One representative card image per series (joined through sets).
        $thumbs = CatalogItem::query()
            ->join('sets', 'sets.id', '=', 'catalog_items.set_id')
            ->where('sets.product_line_id', $line->id)
            ->when($lang, fn (Builder $q, $l) => $q->where('sets.language', $l))
            ->whereNotNull('sets.series')
            ->whereNotNull('catalog_items.primary_image_path')
            ->groupBy('sets.series')
            ->selectRaw('sets.series as series, min(catalog_items.primary_image_path) as thumb')
            ->pluck('thumb', 'series');

        // An admin-set series logo overrides the auto-picked card thumbnail.
        $logos = SeriesMeta::where('product_line_id', $line->id)
            ->whereNotNull('logo_path')
            ->pluck('logo_path', 'name');

        return Set::query()
            ->where('product_line_id', $line->id)
            ->when($lang, fn (Builder $q, $l) => $q->where('language', $l))
            ->whereNotNull('series')
            ->groupBy('series')
            ->selectRaw('series, count(*) as set_count, max(released_at) as newest')
            ->orderByRaw('max(released_at) desc')
            ->get()
            ->map(fn (Set $row) => [
                'kind' => 'series',
                'slug' => $row->series,
                'name' => $row->series,
                'count' => (int) $row->getAttribute('set_count'),
                'thumb' => $logos[$row->series] ?? $thumbs[$row->series] ?? null,
            ])
            ->all();
    }

    /**
     * Subset tiles for a parent set: "Main set" (its own cards) plus any gallery
     * child sets (Trainer Gallery, …). Returns [] when the set has no children —
     * the caller then shows the set's cards directly.
     *
     * @return array<int, array<string, mixed>>
     */
    private function subsets(array $filters): array
    {
        $set = Set::where('slug', $filters['set'] ?? null)->first();

        if (! $set) {
            return [];
        }

        $childNames = array_map(fn (string $suffix) => $set->name.' '.$suffix, Subsets::SUFFIXES);

        $children = Set::query()
            ->where('product_line_id', $set->product_line_id)
            ->where('language', $set->language)
            ->whereIn('name', $childNames)
            ->addSelect(['thumb' => $this->thumb('set_id', 'sets.id')])
            ->addSelect(['item_count' => CatalogItem::query()->selectRaw('count(*)')->whereColumn('set_id', 'sets.id')])
            ->orderBy('name')
            ->get();

        if ($children->isEmpty()) {
            return [];
        }

        // Main set first, then each gallery child.
        $tiles = [[
            'kind' => 'subset',
            'slug' => 'main',
            'set_slug' => null,
            'name' => 'Main set',
            'count' => CatalogItem::where('set_id', $set->id)->count(),
            'thumb' => $set->logo_path ?: CatalogItem::where('set_id', $set->id)
                ->whereNotNull('primary_image_path')->orderBy('id')->value('primary_image_path'),
        ]];

        foreach ($children as $child) {
            [, $suffix] = Subsets::split($child->name);
            $tiles[] = [
                'kind' => 'subset',
                'slug' => $child->slug,
                'set_slug' => $child->slug,
                'name' => $suffix ?? $child->name,
                'count' => (int) $child->getAttribute('item_count'),
                'thumb' => $child->logo_path ?: $child->getAttribute('thumb'),
            ];
        }

        return $tiles;
    }

    /** One representative card image for the parent (brand/set). */
    private function thumb(string $fk, string $parentKey): BuilderContract
    {
        return CatalogItem::query()
            ->select('primary_image_path')
            ->whereColumn($fk, $parentKey)
            ->whereNotNull('primary_image_path')
            ->orderBy('id')
            ->limit(1);
    }
}
