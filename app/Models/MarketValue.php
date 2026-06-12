<?php

namespace App\Models;

use App\Enums\Condition;
use Database\Factories\MarketValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Computed valuation snapshot for one priced state (§4.4). Money fields are
 * integer minor units (cents). The app/API reads only from here.
 */
#[Fillable([
    'catalog_item_id',
    'state_key',
    'condition',
    'grading_company_id',
    'grade',
    'median',
    'p25',
    'p75',
    'low',
    'high',
    'n_sales',
    'confidence',
    'top_seller_share',
    'is_estimated',
    'half_life_days',
    'trend_30d',
    'trend_90d',
    'currency',
    'computed_at',
])]
class MarketValue extends Model
{
    /** @use HasFactory<MarketValueFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'grade' => 'float',
            'median' => 'integer',
            'p25' => 'integer',
            'p75' => 'integer',
            'low' => 'integer',
            'high' => 'integer',
            'n_sales' => 'integer',
            'confidence' => 'float',
            'top_seller_share' => 'float',
            'is_estimated' => 'boolean',
            'half_life_days' => 'integer',
            'trend_30d' => 'float',
            'trend_90d' => 'float',
            'computed_at' => 'datetime',
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
