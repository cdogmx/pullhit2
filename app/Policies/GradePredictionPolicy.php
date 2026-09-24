<?php

namespace App\Policies;

use App\Models\GradePrediction;
use App\Models\User;

/**
 * A saved run belongs to whoever ran it.
 *
 * The bench is admin-only, so everyone who can see these is trusted — but
 * trusted is not the same as entitled. Somebody else's reading is their record
 * of their card: the label they chose, the note they left themselves, and the
 * grade that came back. Editing it, or handing out a link to it, is theirs to
 * do.
 *
 * Reading stays open to any admin on purpose. The whole value of the saved list
 * is comparing runs against each other, and that only works if they are all
 * visible.
 */
class GradePredictionPolicy
{
    public function update(User $user, GradePrediction $prediction): bool
    {
        return $prediction->user_id === $user->id;
    }

    public function delete(User $user, GradePrediction $prediction): bool
    {
        return $prediction->user_id === $user->id;
    }

    /** Sharing publishes it, which is more than editing, not less. */
    public function share(User $user, GradePrediction $prediction): bool
    {
        return $prediction->user_id === $user->id;
    }
}
