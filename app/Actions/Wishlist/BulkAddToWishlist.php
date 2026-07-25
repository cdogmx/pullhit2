<?php

namespace App\Actions\Wishlist;

use App\Models\CatalogItem;
use App\Models\User;

/**
 * Add many cards to one wishlist at once. Per-card through AddToWishlist, whose
 * firstOrNew keeps the add idempotent — re-adding a card already on the list is
 * a no-op rather than a duplicate-key error.
 */
class BulkAddToWishlist
{
    public function __construct(
        protected AddToWishlist $add,
    ) {}

    /**
     * @param  array<int, int>  $catalogItemIds
     * @param  array<string, mixed>  $attrs  wishlist_id?, target_price?, notes?
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
