<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public type-ahead suggestions, grouped brand → set → card. Three small,
 * limited LIKE queries (one per tier), each with a thumbnail and a canonical URL
 * so the header search can link straight to the right page. Read-only.
 */
class SuggestSearch
{
    /** Don't query on a stray keystroke; matches the browse search threshold. */
    public const MIN_LENGTH = 2;

    /**
     * @return array{brands: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>, cards: array<int, array<string, mixed>>}
     */
    public function __invoke(string $q): array
    {
        $q = trim($q);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return ['brands' => [], 'sets' => [], 'cards' => []];
        }

        $like = '%'.$q.'%';

        return [
            'brands' => $this->brands($like),
            'sets' => $this->sets($like),
            'cards' => $this->cards($like),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function brands(string $like): array
    {
        return ProductLine::query()
            ->where('name', 'like', $like)
            ->addSelect(['thumb' => $this->cardThumb('product_line_id', 'product_lines.id')])
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(fn (ProductLine $line) => [
                'name' => $line->name,
                // Admin-set brand logo wins; otherwise a representative card image.
                'thumb' => $line->logo_path ?: $line->getAttribute('thumb'),
                'url' => "/browse/{$line->slug}",
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function sets(string $like): array
    {
        return Set::query()
            ->with('productLine:id,slug,name')
            ->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->addSelect(['thumb' => $this->cardThumb('set_id', 'sets.id')])
            ->orderByDesc('released_at')
            ->limit(6)
            ->get()
            ->filter(fn (Set $set) => $set->productLine !== null)
            ->map(fn (Set $set) => [
                'name' => $set->name,
                'brand' => $set->productLine->name,
                'thumb' => $set->logo_path ?: $set->getAttribute('thumb'),
                'url' => "/browse/{$set->productLine->slug}/{$set->slug}",
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(string $like): array
    {
        return CatalogItem::query()
            ->with(['productLine:id,slug', 'set:id,slug,name'])
            ->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('number', 'like', $like))
            ->orderByDesc('popularity')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (CatalogItem $item) => [
                'name' => $item->display_name,
                'set' => $item->set?->name,
                'thumb' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
                'url' => $item->path() ?? "/catalog/{$item->id}",
            ])
            ->all();
    }

    /** One representative card image for the parent (brand/set) as a subquery. */
    private function cardThumb(string $fk, string $parentKey): BuilderContract
    {
        return CatalogItem::query()
            ->select('primary_image_path')
            ->whereColumn($fk, $parentKey)
            ->whereNotNull('primary_image_path')
            ->orderBy('id')
            ->limit(1);
    }
}
