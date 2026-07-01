<?php

namespace App\Actions\Community;

use App\Enums\ContributionType;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Awards a small daily check-in (once per calendar day) and a streak bonus every
 * N consecutive days. No-ops cheaply when the user has already checked in today,
 * so it's safe to call on every page load.
 */
class RecordDailyCheckin
{
    public function __construct(protected AwardPoints $award) {}

    public function __invoke(User $user): void
    {
        $today = Carbon::today();

        // Already counted today — nothing to do (in-memory check, no query).
        if ($user->last_checkin_on && $user->last_checkin_on->isSameDay($today)) {
            return;
        }

        // Consecutive day extends the streak; any gap resets it to 1.
        $streak = $user->last_checkin_on && $user->last_checkin_on->isSameDay($today->copy()->subDay())
            ? $user->checkin_streak + 1
            : 1;

        $user->forceFill(['last_checkin_on' => $today, 'checkin_streak' => $streak])->save();

        ($this->award)($user, ContributionType::DailyCheckin, description: 'Daily check-in', key: 'date:'.$today->toDateString());

        $every = (int) config('community.streak_bonus_every', 7);
        if ($every > 0 && $streak % $every === 0) {
            ($this->award)($user, ContributionType::StreakBonus, description: "{$streak}-day streak", key: 'streak:'.$today->toDateString());
        }
    }
}
