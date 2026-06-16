<?php

namespace App\Actions\Billing;

use App\Models\User;
use App\Support\Billing\DodoClient;
use InvalidArgumentException;

/**
 * Begin a subscription upgrade to a paid tier (collector | guru): create a Dodo
 * hosted checkout for that tier's product and return the URL to redirect to.
 */
class StartCheckout
{
    public function __construct(
        protected DodoClient $dodo,
    ) {}

    public function __invoke(User $user, string $tier = 'collector'): string
    {
        $productId = (string) config("services.dodo.{$tier}_product_id");

        if ($productId === '') {
            throw new InvalidArgumentException("No Dodo product configured for tier [{$tier}].");
        }

        return $this->dodo->createCheckout($user, $productId, route('billing.return'), [
            'tier' => $tier,
        ]);
    }
}
