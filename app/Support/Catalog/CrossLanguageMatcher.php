<?php

namespace App\Support\Catalog;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Finds the same card in other languages. Two strategies, unioned:
 *
 *  1. Global-number match — for lines whose numbers are globally unique across
 *     sets (One Piece "OP06-081"): same product line + item type + name +
 *     number + variant, different language.
 *
 *  2. Expansion-linked match — for lines whose numbers repeat per set (Pokemon,
 *     where every set restarts at 1): the item's set must carry an
 *     `expansion_key`; we look only inside the sibling sets sharing that key
 *     (its cross-language equivalents) and match on the INTEGER number + a
 *     normalized name, since JP names carry noise like "Beedrill ex - 003/083"
 *     and numbers are zero-padded ("001" vs "1"). Variant is honored so a
 *     normal printing doesn't link to a reverse holo.
 */
class CrossLanguageMatcher
{
    /**
     * @return array<int, array{language: string, name: string, set: string|null, url: string}>
     */
    public function forItem(CatalogItem $item): array
    {
        if (! $item->name || ! $item->number) {
            return [];
        }

        $matches = $this->byGlobalNumber($item)
            ->merge($this->byExpansionKey($item))
            ->reject(fn (CatalogItem $c) => $c->id === $item->id)
            ->unique('id')
            ->sortBy('language')
            ->take(8);

        return $matches
            ->map(fn (CatalogItem $c) => [
                'language' => $c->language,
                'name' => $c->display_name,
                'set' => $c->set?->name,
                'url' => $c->path(),
            ])
            ->filter(fn (array $x) => $x['url'] !== null)
            ->values()
            ->all();
    }

    /** @return Collection<int, CatalogItem> */
    protected function byGlobalNumber(CatalogItem $item): Collection
    {
        return CatalogItem::query()
            ->where('product_line_id', $item->product_line_id)
            ->where('item_type', $item->item_type->value)
            ->where('name', $item->name)
            ->where('number', $item->number)
            ->where('language', '!=', $item->language)
            ->whereKeyNot($item->id)
            ->when(
                $item->getAttribute('attributes')['variant'] ?? null,
                fn (Builder $q, $variant) => $q->where('attributes->variant', $variant),
            )
            ->with(['productLine:id,slug', 'set:id,slug,name'])
            ->limit(6)
            ->get();
    }

    /** @return Collection<int, CatalogItem> */
    protected function byExpansionKey(CatalogItem $item): Collection
    {
        $key = $item->set?->expansion_key;
        $num = $this->intNumber($item->number);

        if (! $key || $num === null) {
            return new Collection;
        }

        $normalized = $this->normalizeName($item->name);

        $candidates = CatalogItem::query()
            ->where('catalog_items.product_line_id', $item->product_line_id)
            ->where('catalog_items.item_type', $item->item_type->value)
            ->where('catalog_items.language', '!=', $item->language)
            ->whereKeyNot($item->id)
            ->whereHas('set', fn (Builder $q) => $q
                ->where('expansion_key', $key)
                ->whereKeyNot($item->set_id))
            ->whereRaw('CAST(catalog_items.number AS UNSIGNED) = ?', [$num])
            ->with(['productLine:id,slug', 'set:id,slug,name'])
            ->limit(20)
            ->get()
            // Name check in PHP so we can strip the "- 003/083" suffix noise.
            ->filter(fn (CatalogItem $c) => $this->normalizeName($c->name) === $normalized)
            ->values();

        // Variant labels aren't consistent across languages (a JP "ex" is
        // tagged holo, its EN twin normal), so don't require equality. Prefer an
        // exact variant match; else drop reverse holos so a normal printing
        // doesn't link to a reverse-holo twin of the same number.
        $variant = $item->getAttribute('attributes')['variant'] ?? null;

        $exact = $variant
            ? $candidates->filter(fn (CatalogItem $c) => ($c->getAttribute('attributes')['variant'] ?? null) === $variant)
            : new Collection;

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        $nonReverse = $candidates->reject(
            fn (CatalogItem $c) => ($c->getAttribute('attributes')['variant'] ?? null) === 'reverse_holo',
        );

        return ($nonReverse->isNotEmpty() ? $nonReverse : $candidates)->values();
    }

    /** Leading integer of a card number: "001" -> 1, "OP06-081" -> null. */
    protected function intNumber(string $number): ?int
    {
        return preg_match('/^\s*0*(\d+)/', $number, $m) && ! preg_match('/[a-z]/i', $number)
            ? (int) $m[1]
            : null;
    }

    /** Drop the "- 003/083" collector suffix, lowercase, collapse whitespace. */
    protected function normalizeName(string $name): string
    {
        $name = preg_replace('/\s*-\s*\d+\/\d+\s*$/', '', $name);

        return Str::of($name)->lower()->squish()->value();
    }
}
