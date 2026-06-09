<?php

namespace App\Models;

use App\Enums\Condition;
use App\Enums\Venue;
use Database\Factories\SaleObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed market signal for a priced state of a catalog item (§4.4).
 * Append-only; `price` is integer minor units (cents).
 */
#[Fillable([
    'catalog_item_id',
    'condition',
    'grading_company_id',
    'grade',
    'grade_label',
    'venue',
    'price',
    'currency',
    'observed_at',
    'source_listing_id',
    'is_outlier',
    'raw',
])]
class SaleObservation extends Model
{
    /** @use HasFactory<SaleObservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'venue' => Venue::class,
            'grade' => 'float',
            'price' => 'integer',
            'observed_at' => 'datetime',
            'is_outlier' => 'boolean',
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
