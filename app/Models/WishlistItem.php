<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card a user wants. Carries an optional target price (cents) so the wishlist
 * can flag "at or below target" when the market value drops. Current value reads
 * the card's headline market value (no per-state machinery like the collection).
 */
class WishlistItem extends Model
{
    protected $fillable = ['user_id', 'catalog_item_id', 'target_price', 'notes'];

    protected function casts(): array
    {
        return [
            'target_price' => 'integer',
        ];
    }

    /** Current headline (ungraded NM) market value in cents, or null. */
    public function currentValue(): ?int
    {
        return $this->catalogItem?->defaultMarketValue?->median;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
