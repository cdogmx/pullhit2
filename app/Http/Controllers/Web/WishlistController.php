<?php

namespace App\Http\Controllers\Web;

use App\Actions\Wishlist\AddToWishlist;
use App\Actions\Wishlist\CreateWishlist;
use App\Actions\Wishlist\PublicWishlist;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\StoreWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Models\CatalogItem;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Membership\Entitlements;
use Illuminate\Http\JsonResponse;
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
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.defaultMarketValue'])
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

    public function store(StoreWishlistItemRequest $request, AddToWishlist $add, CreateWishlist $create): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $item = CatalogItem::findOrFail($data['catalog_item_id']);

        // "New wishlist…" from the picker — create it (tier-gated) and add there.
        if (! empty($data['new_wishlist_name'])) {
            $data['wishlist_id'] = $create($user, $data['new_wishlist_name'])->id;
        }

        $add($user, $item, $data);

        return back()->with('success', 'Added to your wishlist.');
    }

    /**
     * The user's wishlists + whether they can create another (for the picker).
     */
    public function targets(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = Entitlements::for($user)->wishlistLimit();

        return response()->json([
            'targets' => $user->wishlists()->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'is_default' => (bool) $w->is_default]),
            'can_create' => $user->wishlists()->count() < $limit,
            'limit' => $limit === PHP_INT_MAX ? null : $limit,
        ]);
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

    /**
     * Remove a card from the user's wishlist(s).
     *
     * - With `wishlist_id`: remove only from that list (wishlist page X button).
     * - Without: remove from every list that has the card (heart toggle-off —
     *   the filled heart means "on any of my wishlists").
     */
    public function destroy(Request $request, CatalogItem $catalogItem): RedirectResponse
    {
        $data = $request->validate([
            'wishlist_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('wishlists', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $query = $request->user()->wishlistItems()
            ->where('catalog_item_id', $catalogItem->id);

        if (! empty($data['wishlist_id'])) {
            $query->where('wishlist_id', $data['wishlist_id']);
        }

        $query->delete();

        return back()->with('success', 'Removed from your wishlist.');
    }
}
