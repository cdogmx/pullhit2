<?php

namespace App\Actions\Wishlist;

use App\Models\CatalogItem;
use App\Models\User;
use App\Models\WishlistItem;

/**
 * Add a card to a user's wishlist (idempotent — one row per user+card). Updates
 * the target price / notes when supplied. Mirrors AddToCollection, minus the
 * cost-basis machinery.
 */
class AddToWishlist
{
    /**
     * @param  array<string, mixed>  $attrs  target_price?, notes?
     */
    public function __invoke(User $user, CatalogItem $item, array $attrs = []): WishlistItem
    {
        // Items live in a wishlist — the one given, else the user's default
        // (the heart-toggle target).
        $wishlistId = $attrs['wishlist_id'] ?? $user->defaultWishlist()->id;

        $wish = WishlistItem::firstOrNew([
            'user_id' => $user->id,
            'wishlist_id' => $wishlistId,
            'catalog_item_id' => $item->id,
        ]);

        if (array_key_exists('target_price', $attrs)) {
            $wish->target_price = $attrs['target_price'];
        }
        if (array_key_exists('notes', $attrs)) {
            $wish->notes = $attrs['notes'];
        }

        $wish->save();

        return $wish;
    }
}
