<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product we watch for in-stock-at-target deals across multiple retailers.
 * Holds the single target price; each App\Models\RetailerLink is one store.
 * Optionally tied to a catalog item (reuses its name/image, cross-links it).
 */
#[Fillable([
    'catalog_item_id',
    'name',
    'image_url',
    'target_price',
    'currency',
    'check_interval_minutes',
    'is_active',
])]
class TrackedProduct extends Model
{
    protected $attributes = [
        'currency' => 'USD',
        'check_interval_minutes' => 15,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'integer',
            'check_interval_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<RetailerLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(RetailerLink::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** Headline for tweets: admin name → catalog name → (caller falls back to retailer title). */
    public function headline(): ?string
    {
        return $this->name
            ?: ($this->catalogItem?->display_name ?? $this->catalogItem?->name);
    }

    /** Preferred image: admin image → catalog image (retailer image used if null). */
    public function preferredImage(): ?string
    {
        return $this->image_url ?: $this->catalogItem?->image_url;
    }
}
