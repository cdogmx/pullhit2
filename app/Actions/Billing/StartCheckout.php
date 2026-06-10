<?php

namespace App\Actions\Billing;

use App\Models\User;
use App\Support\Billing\DodoClient;

/**
 * Begin a premium upgrade: create a Dodo hosted checkout for the user and return
 * the URL to redirect them to.
 */
class StartCheckout
{
    public function __construct(
        protected DodoClient $dodo,
    ) {}

    public function __invoke(User $user): string
    {
        return $this->dodo->createSubscriptionCheckout($user, route('billing.return'));
    }
}
