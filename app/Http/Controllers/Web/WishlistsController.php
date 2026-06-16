<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Support\Membership\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manage a user's named wishlists (create / rename / share / delete), gated by
 * membership tier. Deleting one moves its items back to the default wishlist.
 */
class WishlistsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $limit = Entitlements::for($user)->wishlistLimit();
        if ($user->wishlists()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "Your plan allows {$limit} wishlist(s). Upgrade for more.",
            ]);
        }

        $wishlist = $user->wishlists()->create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($user->id, $data['name']),
            'sort' => (int) $user->wishlists()->max('sort') + 1,
        ]);

        return back()->with('success', "Created “{$wishlist->name}”.");
    }

    public function update(Request $request, Wishlist $wishlist): RedirectResponse
    {
        abort_unless($wishlist->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'is_public' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data) && $data['name'] !== $wishlist->name) {
            $wishlist->name = $data['name'];
            $wishlist->slug = $this->uniqueSlug($wishlist->user_id, $data['name'], $wishlist->id);
        }

        if (array_key_exists('is_public', $data)) {
            $wishlist->is_public = $data['is_public'];
        }

        $wishlist->save();

        if (! empty($data['is_default']) && ! $wishlist->is_default) {
            $request->user()->wishlists()->where('id', '!=', $wishlist->id)->update(['is_default' => false]);
            $wishlist->update(['is_default' => true]);
        }

        return back()->with('success', 'Wishlist updated.');
    }

    public function destroy(Request $request, Wishlist $wishlist): RedirectResponse
    {
        abort_unless($wishlist->user_id === $request->user()->id, 403);

        if ($wishlist->is_default) {
            throw ValidationException::withMessages(['wishlist' => 'You can’t delete your default wishlist.']);
        }

        // Preserve items: move any not already in the default wishlist over,
        // then delete (a duplicate card already in default is just dropped).
        $default = $request->user()->defaultWishlist();
        $existing = $default->items()->pluck('catalog_item_id');
        $wishlist->items()->whereNotIn('catalog_item_id', $existing)->update(['wishlist_id' => $default->id]);
        $wishlist->delete();

        return back()->with('success', 'Wishlist deleted; its cards moved to your default wishlist.');
    }

    /** A per-user-unique slug derived from the name (appends -2, -3, … on clash). */
    private function uniqueSlug(int $userId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'wishlist';
        $slug = $base;
        $n = 1;

        while (Wishlist::where('user_id', $userId)->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
