<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Enums\DealType;
use Database\Factories\MarketplaceDealFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale the two parties have agreed to, and eventually say happened.
 *
 * A direct deal is a claim CardFoo records and never verifies — that is the
 * whole venue posture. What makes it worth anything is that both sides have to
 * make it: one person confirming is a person talking about themselves.
 */
class MarketplaceDeal extends Model
{
    /** @use HasFactory<MarketplaceDealFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DealType::class,
            'status' => DealStatus::class,
            'agreed_price_cents' => 'integer',
            'buyer_confirmed_at' => 'datetime',
            'seller_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MarketplaceThread::class, 'marketplace_thread_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(MarketplaceFeedback::class, 'marketplace_deal_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_values(array_filter(
            array_map(fn (DealStatus $s) => $s->isOpen() ? $s->value : null, DealStatus::cases()),
        )));
    }

    public function includes(?User $user): bool
    {
        return $user !== null && in_array($user->id, [$this->buyer_id, $this->seller_id], true);
    }

    /** The side that did NOT propose, i.e. the one whose acceptance is needed. */
    public function awaitingAcceptanceFrom(): ?int
    {
        return $this->proposed_by_id === $this->buyer_id ? $this->seller_id : $this->buyer_id;
    }

    public function hasConfirmed(User $user): bool
    {
        return $user->id === $this->buyer_id
            ? $this->buyer_confirmed_at !== null
            : $this->seller_confirmed_at !== null;
    }

    /** Whether this user may still leave feedback on a completed deal. */
    public function canBeRatedBy(?User $user): bool
    {
        return $user !== null
            && $this->status === DealStatus::Complete
            && $this->includes($user)
            && ! $this->feedback()->where('rater_id', $user->id)->exists();
    }
}
