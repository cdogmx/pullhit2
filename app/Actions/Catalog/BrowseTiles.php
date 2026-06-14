<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Smart browse" navigation tiles: brand tiles when nothing is selected, set
 * tiles (counts + a representative card thumbnail) once a brand is chosen. Lets
 * browse drill brand → set → card instead of dumping the whole catalog up front.
 */
class BrowseTiles
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(string $mode, array $filters): array
    {
        return $mode === 'sets' ? $this->sets($filters) : $this->brands($filters);
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
                'thumb' => $line->getAttribute('thumb'),
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

        return Set::query()
            ->where('product_line_id', $line->id)
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', $lang))
            ->addSelect(['thumb' => $this->thumb('set_id', 'sets.id')])
            ->addSelect(['item_count' => CatalogItem::query()->selectRaw('count(*)')->whereColumn('set_id', 'sets.id')])
            ->orderByDesc('released_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Set $set) => [
                'kind' => 'set',
                'slug' => $set->slug,
                'name' => $set->name,
                'code' => $set->code,
                'language' => $set->language,
                'released_at' => $set->released_at?->toDateString(),
                'count' => (int) $set->getAttribute('item_count'),
                'thumb' => $set->getAttribute('thumb'),
            ])
            ->filter(fn (array $t) => $t['count'] > 0)
            ->values()
            ->all();
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
