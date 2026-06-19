<?php

namespace App\Http\Controllers\Web;

use App\Actions\Wishlist\AddToWishlist;
use App\Actions\Wishlist\PublicWishlist;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\StoreWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Models\CatalogItem;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Membership\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $user = $request->user();

        $default = $user->defaultWishlist();
        $wishlists = $user->wishlists()->withCount('items')
            ->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get();

        $active = $wishlists->firstWhere('slug', $request->query('wishlist')) ?? $default;

        $items = $active->items()
            ->with(['catalogItem.set', 'catalogItem.defaultMarketValue'])
            ->latest()
            ->get();

        $resolved = WishlistItemResource::collection($items)->resolve();

        $publicUrl = $active->is_public && $user->username
            ? url('/wishlist/'.$user->username.($active->is_default ? '' : '/'.$active->slug))
            : null;

        return Inertia::render('wishlist/index', [
            'wishlists' => $wishlists->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'is_public' => $w->is_public,
                'is_default' => $w->is_default,
                'items_count' => $w->items_count,
            ]),
            'activeWishlist' => $active->slug,
            'wishlistLimit' => ($lim = Entitlements::for($user)->wishlistLimit()) === PHP_INT_MAX ? null : $lim,
            'items' => $resolved,
            'summary' => [
                'item_count' => count($resolved),
                'below_target' => collect($resolved)->where('below_target', true)->count(),
                'currency' => 'USD',
            ],
            'publicUrl' => $publicUrl,
        ]);
    }

    /** Public, shareable wishlist page — the owner's default wishlist. */
    public function publicShow(string $username, PublicWishlist $build): Response
    {
        return $this->renderPublic($username, null, $build);
    }

    /** Public page for a specific named wishlist. */
    public function publicShowWishlist(string $username, string $wishlistSlug, PublicWishlist $build): Response
    {
        return $this->renderPublic($username, $wishlistSlug, $build);
    }

    private function renderPublic(string $username, ?string $slug, PublicWishlist $build): Response
    {
        $user = User::where('username', $username)->first();
        abort_unless((bool) $user, 404);

        $wishlist = $slug !== null
            ? $user->wishlists()->where('slug', $slug)->first()
            : $user->wishlists()->where('is_default', true)->first();

        abort_unless($wishlist && $wishlist->is_public, 404);

        // The owner viewing their own public page can edit items in place.
        $owner = auth()->id() === $user->id;
        $data = $build($wishlist, $owner);

        // Server-rendered share meta (social scrapers don't run JS).
        $title = $wishlist->is_default
            ? "{$user->username}'s wishlist"
            : "{$user->username}'s {$wishlist->name} wishlist";
        $data['meta'] = [
            'title' => $title,
            'description' => number_format($data['summary']['item_count']).' cards · '
                .'$'.number_format($data['summary']['total_value'] / 100, 2)
                .' on CardFoo.',
        ];

        return Inertia::render('wishlist/public', $data);
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
            // Move an item to another of the user's wishlists.
            'wishlist_id' => ['sometimes', 'integer', Rule::exists('wishlists', 'id')->where('user_id', $request->user()->id)],
        ]));

        return back()->with('success', 'Wishlist updated.');
    }

    /** Remove the card from the user's default wishlist (the heart toggle-off). */
    public function destroy(Request $request, CatalogItem $catalogItem): RedirectResponse
    {
        $request->user()->defaultWishlist()->items()
            ->where('catalog_item_id', $catalogItem->id)
            ->delete();

        return back()->with('success', 'Removed from your wishlist.');
    }
}
