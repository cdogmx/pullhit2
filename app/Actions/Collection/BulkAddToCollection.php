<?php

namespace App\Actions\Collection;

use App\Models\CatalogItem;
use App\Models\User;

/**
 * Add many cards to a collection in one go, all sharing the same priced state
 * (condition or grade), quantity, and cost. Routed through AddToCollection per
 * card so each still merges into an existing holding and records its own
 * acquisition lot — a raw insert would lose the cost basis and duplicate rows.
 */
class BulkAddToCollection
{
    public function __construct(
        protected AddToCollection $add,
    ) {}

    /**
     * @param  array<int, int>  $catalogItemIds
     * @param  array<string, mixed>  $attrs  the shared state applied to every card
     * @return int how many cards were added (unknown ids are skipped, not an error)
     */
    public function __invoke(User $user, array $catalogItemIds, array $attrs = []): int
    {
        $items = CatalogItem::whereIn('id', array_values(array_unique($catalogItemIds)))->get();

        foreach ($items as $item) {
            ($this->add)($user, $item, $attrs);
        }

        return $items->count();
    }
}
