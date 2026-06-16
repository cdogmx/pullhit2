<?php

namespace App\Actions\Billing;

use App\Models\User;
use App\Support\Billing\DodoClient;
use InvalidArgumentException;

/**
 * Begin a one-time scan-credit-pack purchase: create a Dodo hosted checkout for
 * the pack's product, tagging the credit count in metadata so the webhook can
 * grant the credits on payment.
 */
class BuyCreditPack
{
    public function __construct(
        protected DodoClient $dodo,
    ) {}

    public function __invoke(User $user, string $pack): string
    {
        $config = config("membership.credit_packs.{$pack}");

        if (! $config || empty($config['dodo_product'])) {
            throw new InvalidArgumentException("No Dodo product configured for credit pack [{$pack}].");
        }

        return $this->dodo->createCheckout($user, (string) $config['dodo_product'], route('billing.return'), [
            'type' => 'credits',
            'pack' => $pack,
            'credits' => (string) $config['credits'],
        ]);
    }
}
