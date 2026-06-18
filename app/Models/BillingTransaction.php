<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One money movement from Dodo Payments (a charge, renewal, credit-pack purchase,
 * failed payment, or refund). Created only by the billing webhook seam. The
 * payload column keeps the full provider event for auditing.
 */
#[Fillable([
    'user_id', 'type', 'status', 'event_type', 'amount', 'currency',
    'tier', 'credits', 'description', 'dodo_payment_id', 'dodo_subscription_id',
    'payload', 'processed_at',
])]
class BillingTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'credits' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** Dollar value of the amount (minor units → major), or null when unknown. */
    public function amountInMajorUnits(): ?float
    {
        return $this->amount === null ? null : $this->amount / 100;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
