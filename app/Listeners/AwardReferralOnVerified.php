<?php

namespace App\Listeners;

use App\Actions\Community\AwardPoints;
use App\Enums\ContributionType;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Awards the referrer their points once a referred user verifies their email —
 * so unverified throwaway sign-ups can't farm giveaway entries. Idempotent per
 * referred user (subject).
 */
class AwardReferralOnVerified
{
    public function __construct(protected AwardPoints $award) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->referred_by_user_id) {
            return;
        }

        $referrer = User::find($user->referred_by_user_id);

        if ($referrer) {
            ($this->award)($referrer, ContributionType::Referral, subject: $user, description: 'Referred a new member');
        }
    }
}
