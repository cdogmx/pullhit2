<?php

namespace App\Models;

use App\Enums\Condition;
use App\Enums\ListingCategory;
use App\Enums\ListingStatus;
use Database\Factories\MarketplaceListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One card a user is selling. See the migration for why it is not `listings`.
 */
class MarketplaceListing extends Model
{
    /** @use HasFactory<MarketplaceListingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => ListingCategory::class,
            'status' => ListingStatus::class,
            'condition' => Condition::class,
            'price_cents' => 'integer',
            'accepts_offers' => 'boolean',
            'accepts_direct' => 'boolean',
            'accepts_escrow' => 'boolean',
            'bumped_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function gradingCompany(): BelongsTo
    {
        return $this->belongsTo(GradingCompany::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(MarketplaceListingPhoto::class)->orderBy('sort_order');
    }

    /** What a buyer may see: live, unexpired, and from someone not banned. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('status', array_map(fn (ListingStatus $s) => $s->value, ListingStatus::public()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('user', fn (Builder $u) => $u->whereNull('banned_at'));
    }

    /** Bumped listings first, then newest — the browse order. */
    public function scopeRanked(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(bumped_at, created_at) DESC')
            ->orderByDesc('id');
    }

    public function isEditableBy(?User $user): bool
    {
        return $user !== null
            && $user->id === $this->user_id
            && $this->status->isEditable();
    }

    /** The first photo, or null — what browse tiles show. */
    public function coverPhoto(): ?MarketplaceListingPhoto
    {
        return $this->photos->first();
    }
}
