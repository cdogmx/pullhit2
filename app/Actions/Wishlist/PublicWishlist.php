<?php

namespace App\Actions\Wishlist;

use App\Models\Wishlist;
use App\Models\WishlistItem;

/**
 * The PUBLIC view of a named wishlist: the cards they want + current market
 * value, sorted by value. Deliberately omits target prices and notes — those
 * stay private (a public wishlist shows WHAT, not what you'd pay).
 *
 * When the viewer owns the wishlist ($owner), each item also carries its target
 * price + notes and the owner's other wishlists, so they can edit in place.
 */
class PublicWishlist
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Wishlist $wishlist, bool $owner = false): array
    {
        $user = $wishlist->user;
        $rows = $wishlist->items()
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.defaultMarketValue'])
            ->get()
            ->map(function (WishlistItem $w) use ($owner) {
                $catalog = $w->catalogItem;

                $item = [
                    'catalog_item_id' => $catalog?->id,
                    'url' => $catalog?->path(),
                    'name' => $catalog?->display_name,
                    'number' => $catalog?->number,
                    'image_url' => $catalog?->primary_image_path
                        ?? ($catalog?->external_ids['ptcgio_image'] ?? null),
                    'set' => $catalog?->set?->name,
                    'current_value' => $w->currentValue(),
                    'currency' => 'USD',
                ];

                if ($owner) {
                    // Fields the edit modal needs (owner only).
                    $item += [
                        'id' => $w->id,
                        'target_price' => $w->target_price,
                        'notes' => $w->notes,
                    ];
                }

                return ['value' => $w->currentValue(), 'item' => $item];
            });

        return [
            'owner' => [
                // Public pages identify the owner by their handle, never their real name.
                'username' => $user->username,
            ],
            'wishlist' => [
                'name' => $wishlist->name,
                'slug' => $wishlist->slug,
                'is_default' => $wishlist->is_default,
            ],
            'summary' => [
                'item_count' => $rows->count(),
                'total_value' => (int) $rows->sum('value'),
                'currency' => 'USD',
            ],
            'items' => $rows->sortByDesc('value')->pluck('item')->values()->all(),
            // Owner-only edit context — the modal's move target list.
            'canEdit' => $owner,
            'wishlists' => $owner
                ? $user->wishlists()->orderByDesc('is_default')->orderBy('name')
                    ->get(['id', 'name', 'slug'])->all()
                : [],
        ];
    }
}
