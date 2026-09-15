<?php

namespace App\Models;

use Database\Factories\MarketplaceListingPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One image on a marketplace listing, stored in our own bucket. */
class MarketplaceListingPhoto extends Model
{
    /** @use HasFactory<MarketplaceListingPhotoFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }
}
