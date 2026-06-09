<?php

namespace App\Policies;

use App\Models\CollectionItem;
use App\Models\User;

/**
 * A user may only touch their own holdings. Auto-discovered by Laravel
 * (App\Policies\CollectionItemPolicy ↔ App\Models\CollectionItem).
 */
class CollectionItemPolicy
{
    public function view(User $user, CollectionItem $item): bool
    {
        return $item->user_id === $user->id;
    }

    public function update(User $user, CollectionItem $item): bool
    {
        return $item->user_id === $user->id;
    }

    public function delete(User $user, CollectionItem $item): bool
    {
        return $item->user_id === $user->id;
    }
}
