<?php

namespace App\Models;

use Database\Factories\MarketplaceThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One conversation between a buyer and a seller about one listing. */
class MarketplaceThread extends Model
{
    /** @use HasFactory<MarketplaceThreadFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'buyer_read_at' => 'datetime',
            'seller_read_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceMessage::class, 'marketplace_thread_id')->orderBy('id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(MarketplaceDeal::class, 'marketplace_thread_id');
    }

    /** Threads this user is a party to — nobody else may see one at all. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id));
    }

    public function includes(?User $user): bool
    {
        return $user !== null && in_array($user->id, [$this->buyer_id, $this->seller_id], true);
    }

    public function otherParty(User $user): ?User
    {
        return $user->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    /** Mark the thread read for whichever side is looking at it. */
    public function markReadBy(User $user): void
    {
        $column = $user->id === $this->buyer_id ? 'buyer_read_at' : 'seller_read_at';

        $this->forceFill([$column => now()])->save();
    }

    public function isUnreadBy(User $user): bool
    {
        if ($this->last_message_at === null) {
            return false;
        }

        $seen = $user->id === $this->buyer_id ? $this->buyer_read_at : $this->seller_read_at;

        return $seen === null || $seen->lt($this->last_message_at);
    }
}
