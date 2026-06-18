<?php

namespace App\Actions\Billing;

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Persist a money-movement row for a verified Dodo event. Only payment and refund
 * events are real transactions — subscription lifecycle events (active/cancelled)
 * just flip the tier and are ignored here. Keyed on the provider payment id +
 * event so retried webhooks update rather than duplicate.
 */
class RecordBillingTransaction
{
    private const SUCCEEDED = ['payment.succeeded'];

    private const FAILED = ['payment.failed'];

    private const REFUNDED = ['payment.refunded', 'refund.succeeded', 'refund.created'];

    /** @param  array<string, mixed>  $payload */
    public function __invoke(array $payload, ?User $user): ?BillingTransaction
    {
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        $status = $this->statusFor($type);
        if ($status === null) {
            return null; // Not a transaction-bearing event.
        }

        $credits = (int) data_get($data, 'metadata.credits', 0);
        $kind = $status === 'refunded' ? 'refund' : ($credits > 0 ? 'credits' : 'subscription');
        $tier = data_get($data, 'metadata.tier');

        $paymentId = (string) (data_get($data, 'payment_id') ?? data_get($data, 'id') ?? '');
        $attributes = [
            'user_id' => $user?->id,
            'type' => $kind,
            'status' => $status,
            'event_type' => $type,
            'amount' => $this->intOrNull(data_get($data, 'total_amount') ?? data_get($data, 'amount')),
            'currency' => data_get($data, 'currency'),
            'tier' => $tier ? (string) $tier : null,
            'credits' => $credits > 0 ? $credits : null,
            'description' => $this->describe($kind, $tier, $credits),
            'dodo_subscription_id' => data_get($data, 'subscription_id') ?? data_get($data, 'id'),
            'payload' => $data,
            'processed_at' => Carbon::now(),
        ];

        // With a payment id we can dedupe a retried event; without one, just insert.
        if ($paymentId !== '') {
            return BillingTransaction::updateOrCreate(
                ['dodo_payment_id' => $paymentId, 'event_type' => $type],
                $attributes,
            );
        }

        return BillingTransaction::create($attributes);
    }

    private function statusFor(string $type): ?string
    {
        return match (true) {
            in_array($type, self::SUCCEEDED, true) => 'succeeded',
            in_array($type, self::FAILED, true) => 'failed',
            in_array($type, self::REFUNDED, true) => 'refunded',
            default => null,
        };
    }

    private function describe(string $kind, mixed $tier, int $credits): string
    {
        return match ($kind) {
            'credits' => number_format($credits).' scan credits',
            'refund' => 'Refund',
            default => $tier ? ucfirst((string) $tier).' plan' : 'Subscription',
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
