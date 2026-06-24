<?php

namespace App\Actions\Collection;

use App\Models\Collection;
use App\Models\User;
use App\Support\Membership\Entitlements;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a named collection for a user, enforcing the per-tier limit. Shared by
 * the collections manager and the "add to collection" flow (create-and-add).
 */
class CreateCollection
{
    public function __invoke(User $user, string $name): Collection
    {
        $limit = Entitlements::for($user)->collectionLimit();

        if ($user->collections()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "Your plan allows {$limit} collection(s). Upgrade for more.",
            ]);
        }

        return $user->collections()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($user->id, $name),
            'sort' => (int) $user->collections()->max('sort') + 1,
        ]);
    }

    private function uniqueSlug(int $userId, string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $n = 1;

        while (Collection::where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
