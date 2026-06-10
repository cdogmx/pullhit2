<?php

namespace App\Actions\Billing;

use App\Enums\MembershipTier;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Apply a verified Dodo subscription webhook to a user's membership: activate
 * premium on active/renewed, downgrade to free on cancel/expire/failure. The
 * single place the membership tier is flipped by billing. Idempotent.
 */
class ApplySubscriptionWebhook
{
    private const ACTIVATING = ['subscription.active', 'subscription.renewed'];

    private const DOWNGRADING = [
        'subscription.cancelled', 'subscription.expired',
        'subscription.failed', 'subscription.on_hold',
    ];

    /** @param  array<string, mixed>  $payload */
    public function __invoke(array $payload): ?User
    {
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        $user = $this->resolveUser($data);
        if (! $user) {
            return null;
        }

        $subscriptionId = data_get($data, 'subscription_id') ?? data_get($data, 'data.id') ?? data_get($data, 'id');
        $activating = in_array($type, self::ACTIVATING, true)
            || ($type === 'payment.succeeded' && $subscriptionId);

        if ($activating) {
            $user->forceFill([
                'membership_tier' => MembershipTier::Premium,
                'dodo_subscription_id' => $subscriptionId ?: $user->dodo_subscription_id,
                'dodo_customer_id' => data_get($data, 'customer.customer_id') ?? $user->dodo_customer_id,
                'membership_renews_at' => $this->date(data_get($data, 'next_billing_date')),
                'membership_cancel_scheduled' => false,
            ])->save();
        } elseif (in_array($type, self::DOWNGRADING, true)) {
            $user->forceFill([
                'membership_tier' => MembershipTier::Free,
                'membership_cancel_scheduled' => false,
                'dodo_subscription_id' => null,
                'membership_renews_at' => null,
            ])->save();
        }

        return $user;
    }

    /** @param  array<string, mixed>  $data */
    protected function resolveUser(array $data): ?User
    {
        if ($userId = data_get($data, 'metadata.user_id')) {
            if ($user = User::find($userId)) {
                return $user;
            }
        }

        $email = data_get($data, 'customer.email');

        return $email ? User::where('email', $email)->first() : null;
    }

    protected function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
