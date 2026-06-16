<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named wishlist owned by a user. Wishlist items belong to one wishlist. Every
 * user has exactly one `is_default` wishlist (the heart-toggle target); the
 * number of additional wishlists is gated by membership tier.
 */
class Wishlist extends Model
{
    /** @use HasFactory<\Database\Factories\WishlistFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'is_public',
        'is_default',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }
}
