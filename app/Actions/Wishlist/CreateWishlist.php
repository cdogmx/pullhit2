<?php

namespace App\Actions\Wishlist;

use App\Models\User;
use App\Models\Wishlist;
use App\Support\Membership\Entitlements;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a named wishlist for a user, enforcing the per-tier limit. Shared by
 * the wishlists manager and the "add to wishlist" flow (create-and-add).
 */
class CreateWishlist
{
    public function __invoke(User $user, string $name): Wishlist
    {
        $limit = Entitlements::for($user)->wishlistLimit();

        if ($user->wishlists()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "Your plan allows {$limit} wishlist(s). Upgrade for more.",
            ]);
        }

        return $user->wishlists()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($user->id, $name),
            'sort' => (int) $user->wishlists()->max('sort') + 1,
        ]);
    }

    private function uniqueSlug(int $userId, string $name): string
    {
        $base = Str::slug($name) ?: 'wishlist';
        $slug = $base;
        $n = 1;

        while (Wishlist::where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
