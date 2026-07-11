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

    /**
     * A price surge — recent sales are up sharply over the trailing baseline.
     * Real data only, backed by enough sales to trust, and not one seller
     * pumping it (guards against a manipulated spike).
     */
    public function isSurging(): bool
    {
        $threshold = (float) config('valuation.surge_pct', 25);
        // A credible surge, not a thin-prior artifact (e.g. a "+6200%" jump from
        // one sparse earlier sale) — those would make the flag look broken.
        $ceiling = (float) config('valuation.surge_max_pct', 300);
        $trend = (float) ($this->trend_30d ?? 0);

        return ! $this->is_estimated
            && $trend >= $threshold
            && $trend <= $ceiling
            && (int) ($this->n_sales ?? 0) >= 5
            && ($this->top_seller_share === null || (float) $this->top_seller_share <= 0.5);
    }
}
