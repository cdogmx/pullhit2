<?php

namespace App\Actions\Wishlist;

use App\Models\User;
use App\Models\WishlistItem;

/**
 * The PUBLIC view of a user's wishlist: the cards they want + current market
 * value, sorted by value. Deliberately omits target prices and notes — those
 * stay private (a public wishlist shows WHAT, not what you'd pay).
 */
class PublicWishlist
{
    /**
     * @return array{owner: array<string, mixed>, summary: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function __invoke(User $user): array
    {
        $rows = $user->wishlistItems()
            ->with(['catalogItem.set', 'catalogItem.defaultMarketValue'])
            ->get()
            ->map(function (WishlistItem $w) {
                $catalog = $w->catalogItem;

                return [
                    'value' => $w->currentValue(),
                    'item' => [
                        'catalog_item_id' => $catalog?->id,
                        'name' => $catalog?->display_name,
                        'number' => $catalog?->number,
                        'image_url' => $catalog?->primary_image_path
                            ?? ($catalog?->external_ids['ptcgio_image'] ?? null),
                        'set' => $catalog?->set?->name,
                        'current_value' => $w->currentValue(),
                        'currency' => 'USD',
                    ],
                ];
            });

        return [
            'owner' => [
                'name' => $user->name,
                'username' => $user->username,
            ],
            'summary' => [
                'item_count' => $rows->count(),
                'total_value' => (int) $rows->sum('value'),
                'currency' => 'USD',
            ],
            'items' => $rows->sortByDesc('value')->pluck('item')->values()->all(),
        ];
    }
}
