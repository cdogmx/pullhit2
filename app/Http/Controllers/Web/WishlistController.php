<?php

namespace App\Http\Controllers\Web;

use App\Actions\Wishlist\AddToWishlist;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\StoreWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Models\CatalogItem;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A user's wishlist — cards they want, each with an optional target price. Thin;
 * delegates to the Wishlist Actions. Always free for logged-in users.
 */
class WishlistController extends Controller
{
    public function index(Request $request): Response
    {
        $items = $request->user()->wishlistItems()
            ->with(['catalogItem.set', 'catalogItem.defaultMarketValue'])
            ->latest()
            ->get();

        $resolved = WishlistItemResource::collection($items)->resolve();

        return Inertia::render('wishlist/index', [
            'items' => $resolved,
            'summary' => [
                'item_count' => count($resolved),
                'below_target' => collect($resolved)->where('below_target', true)->count(),
                'currency' => 'USD',
            ],
        ]);
    }

    public function store(StoreWishlistItemRequest $request, AddToWishlist $add): RedirectResponse
    {
        $item = CatalogItem::findOrFail($request->validated('catalog_item_id'));

        $add($request->user(), $item, $request->validated());

        return back()->with('success', 'Added to your wishlist.');
    }

    public function update(Request $request, WishlistItem $wishlistItem): RedirectResponse
    {
        $this->authorize('update', $wishlistItem);

        $wishlistItem->update($request->validate([
            'target_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]));

        return back()->with('success', 'Wishlist updated.');
    }

    /** Remove the user's wishlist entry for a card (toggle-off from anywhere). */
    public function destroy(Request $request, CatalogItem $catalogItem): RedirectResponse
    {
        $request->user()->wishlistItems()
            ->where('catalog_item_id', $catalogItem->id)
            ->delete();

        return back()->with('success', 'Removed from your wishlist.');
    }
}
