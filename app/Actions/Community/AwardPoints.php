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
    /**
     * @param  string|null  $key  idempotency key for repeatable-but-capped awards
     *                            (e.g. a date for daily check-ins, a fingerprint
     *                            for scan feedback). Null + no subject = once ever.
     */
    public function __invoke(
        User $user,
        ContributionType $type,
        ?Model $subject = null,
        ?string $description = null,
        ?string $key = null,
    ): ?Contribution {
        $points = $type->points();

        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $type, $subject, $description, $points, $key) {
            $query = Contribution::where('user_id', $user->id)->where('type', $type->value);

            if ($subject) {
                $query->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey());
            }

            if ($key !== null) {
                $query->where('dedupe_key', $key);
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
                'dedupe_key' => $key,
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
