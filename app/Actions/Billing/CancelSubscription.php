<?php

namespace App\Actions\Billing;

use App\Models\User;
use App\Support\Billing\DodoClient;

/**
 * Schedule the user's premium subscription to cancel at period end. They keep
 * premium until the webhook confirms the subscription has actually ended.
 */
class CancelSubscription
{
    public function __construct(
        protected DodoClient $dodo,
    ) {}

    public function __invoke(User $user): bool
    {
        if (! $user->dodo_subscription_id) {
            return false;
        }

        $this->dodo->cancelSubscription($user->dodo_subscription_id);

        $user->forceFill(['membership_cancel_scheduled' => true])->save();

        return true;
    }
}
