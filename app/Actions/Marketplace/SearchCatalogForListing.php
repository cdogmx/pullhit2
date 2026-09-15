<?php

namespace App\Actions\Marketplace;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Support\Catalog\LikeTerm;
use App\Support\Marketplace\CardHit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Find the catalogued card a seller is holding, so their listing can point at it.
 *
 * Separate from the header's SuggestSearch, which answers "where do I navigate"
 * and returns a name and a URL. A seller is answering a different question —
 * "which printing is the one in my hand" — and that turns on the collector
 * number and the set, not the name. Two Pikachu in one set differ only by it.
 *
 * Each hit carries what the card is worth, because linking is otherwise a chore
 * with no reward: the number a seller most wants while typing a price is what
 * the thing actually sells for.
 */
class SearchCatalogForListing
{
    private const LIMIT = 8;

    private const MIN_LENGTH = 2;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(string $query, ?string $productLine = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [];
        }

        $items = CatalogItem::query()
            ->with(['set:id,name,code,slug', 'productLine:id,slug,name'])
            ->whereIn('item_type', [ItemType::Single, ItemType::Sealed])
            ->when($productLine, fn (Builder $q, $slug) => $q
                ->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->where(fn (Builder $q) => $this->matches($q, $query))
            // A card people actually look at beats a same-named one nobody does,
            // which is usually the difference between the English print and an
            // obscure foreign reprint.
            ->orderByDesc('popularity')
            ->limit(self::LIMIT)
            ->get();

        $values = $this->rawValues($items->pluck('id')->all());

        return $items
            ->map(fn (CatalogItem $item) => CardHit::for($item, $values[$item->id] ?? null))
            ->all();
    }

    /**
     * Every word has to hit something — the name, the number or the set. That is
     * what lets "charizard 223 obsidian" land on one card, where matching any
     * single word would bury it under every Charizard ever printed.
     */
    private function matches(Builder $query, string $raw): Builder
    {
        foreach (array_slice(preg_split('/\s+/', $raw) ?: [], 0, 6) as $word) {
            $term = LikeTerm::clean($word);

            if ($term === '') {
                continue;
            }

            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('number', 'like', "{$term}%")
                ->orWhereHas('set', fn (Builder $s) => $s
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "{$term}%")));
        }

        return $query;
    }

    /**
     * The ungraded value per card, which is the one a seller prices against —
     * a slab's worth depends on a grade this search does not know.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function rawValues(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MarketValue::query()
            ->whereIn('catalog_item_id', $ids)
            ->whereNull('grading_company_id')
            ->whereIn('state_key', ['NM', 'SEALED'])
            ->pluck('median', 'catalog_item_id')
            ->map(fn ($cents) => (int) $cents)
            ->all();
    }
}
