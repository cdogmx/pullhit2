<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WishlistItem;

class WishlistItemPolicy
{
    public function update(User $user, WishlistItem $item): bool
    {
        return $item->user_id === $user->id;
    }

    public function delete(User $user, WishlistItem $item): bool
    {
        return $item->user_id === $user->id;
    }
}
