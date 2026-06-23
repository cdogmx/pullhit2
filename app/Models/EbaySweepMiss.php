<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sold eBay listing the broad sweep saw but did not apply to a card, kept for
 * match-quality tuning. See App\Actions\Valuation\SweepEbaySold.
 */
#[Fillable([
    'search_label',
    'source_listing_id',
    'title',
    'price',
    'sold_at',
    'parsed_number',
    'reason',
    'best_catalog_item_id',
    'best_score',
])]
class EbaySweepMiss extends Model
{
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'price' => 'integer',
            'best_score' => 'float',
        ];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function bestCatalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
