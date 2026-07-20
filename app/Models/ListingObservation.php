<?php

namespace App\Models;

use App\Enums\Condition;
use App\Enums\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One current asking price (an active "buy it now" listing) for a priced state
 * of a catalog item — the raw material for the "for sale" valuation, parallel to
 * SaleObservation (which records realized sales). Asks are ephemeral, so these
 * are replaced wholesale on each refresh. `price` is integer minor units (cents).
 */
#[Fillable([
    'catalog_item_id',
    'state_key',
    'condition',
    'grading_company_id',
    'grade',
    'venue',
    'price',
    'currency',
    'source_listing_id',
    'seller',
    'url',
    'observed_at',
    'raw',
])]
class ListingObservation extends Model
{
    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'venue' => Venue::class,
            'grade' => 'float',
            'price' => 'integer',
            'observed_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** @return BelongsTo<GradingCompany, $this> */
    public function gradingCompany(): BelongsTo
    {
        return $this->belongsTo(GradingCompany::class);
    }
}
