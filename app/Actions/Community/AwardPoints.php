<?php

namespace App\Actions\Community;

use App\Enums\ContributionType;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single place points are granted: write a ledger row and bump the user's
 * denormalized lifetime total atomically. Idempotent per (subject, type) so a
 * re-approval can't double-award.
 */
class AwardPoints
{
    public function __invoke(
        User $user,
        ContributionType $type,
        ?Model $subject = null,
        ?string $description = null,
    ): ?Contribution {
        $points = $type->points();

        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $type, $subject, $description, $points) {
            $query = Contribution::where('user_id', $user->id)->where('type', $type->value);

            if ($subject) {
                $query->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey());
            }

            // Already awarded for this exact contribution — don't double-count.
            if ($query->exists()) {
                return null;
            }

            $contribution = new Contribution([
                'user_id' => $user->id,
                'type' => $type,
                'points' => $points,
                'description' => $description,
            ]);

            if ($subject) {
                $contribution->subject()->associate($subject);
            }

            $contribution->save();

            $user->increment('contribution_points', $points);

            return $contribution;
        });
    }
}
