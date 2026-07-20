<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use App\Support\Valuation\CombinedValue;
use App\Support\Valuation\Observation;
use App\Support\Valuation\PricedStateKey;
use App\Support\Valuation\ValuationEngine;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Recompute the market_values row for ONE priced state of a catalog item:
 * pull its observations within the lookback window, run the engine, flag
 * outliers, and upsert the snapshot. Reads come only from market_values (§13).
 */
class RecomputeMarketValue
{
    public function __construct(
        protected ValuationEngine $engine,
    ) {}

    public function __invoke(
        CatalogItem $item,
        ?string $condition,
        ?int $gradingCompanyId,
        ?float $grade,
    ): ?MarketValue {
        $cutoff = Carbon::now()->subDays((int) config('valuation.lookback_days'));

        $rows = $this->stateQuery($item, $condition, $gradingCompanyId, $grade)
            ->where('observed_at', '>=', $cutoff)
            ->get();

        $observations = $rows->map(fn ($o) => new Observation(
            priceCents: $o->price,
            venue: $o->venue->value,
            observedAt: $o->observed_at,
            key: $o->id,
            seller: $o->seller,
        ))->all();

        $result = $this->engine->value($observations);

        $stateKey = PricedStateKey::for($condition, $gradingCompanyId, $grade);

        if ($result === null) {
            // No usable data — drop any stale snapshot for this state.
            $item->marketValues()->where('state_key', $stateKey)->delete();

            return null;
        }

        // Persist outlier flags (current computation) for this state.
        $outlierIds = array_filter($result->outlierKeys);
        $this->stateQuery($item, $condition, $gradingCompanyId, $grade)
            ->update(['is_outlier' => false]);
        if ($outlierIds !== []) {
            $item->saleObservations()->whereIn('id', $outlierIds)->update(['is_outlier' => true]);
        }

        // The sold median moved, so re-blend combined against the existing for-sale
        // value (for-sale itself is owned by IngestForSaleListings; leave it be).
        $forSale = MarketValue::where('catalog_item_id', $item->id)
            ->where('state_key', $stateKey)
            ->value('for_sale');

        return MarketValue::updateOrCreate(
            ['catalog_item_id' => $item->id, 'state_key' => $stateKey],
            [
                'condition' => $condition,
                'grading_company_id' => $gradingCompanyId,
                'grade' => $grade,
                'median' => $result->median,
                'p25' => $result->p25,
                'p75' => $result->p75,
                'low' => $result->low,
                'high' => $result->high,
                'n_sales' => $result->nSales,
                'confidence' => $result->confidence,
                'top_seller_share' => $result->topSellerShare,
                // Estimated when the comps are synthetic placeholders (no real sales yet).
                'is_estimated' => $rows->contains(fn ($o) => $o->is_synthetic),
                'half_life_days' => $result->halfLifeDays,
                'trend_1d' => $result->trend1d,
                'trend_7d' => $result->trend7d,
                'trend_30d' => $result->trend30d,
                'trend_90d' => $result->trend90d,
                'combined' => CombinedValue::blend($result->median, $forSale),
                'currency' => $rows->first()->currency ?? 'USD',
                'computed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * @return HasMany<SaleObservation, CatalogItem>
     */
    protected function stateQuery(CatalogItem $item, ?string $condition, ?int $gradingCompanyId, ?float $grade)
    {
        $query = $item->saleObservations();

        $condition === null ? $query->whereNull('condition') : $query->where('condition', $condition);
        $gradingCompanyId === null ? $query->whereNull('grading_company_id') : $query->where('grading_company_id', $gradingCompanyId);
        $grade === null ? $query->whereNull('grade') : $query->where('grade', $grade);

        return $query;
    }
}
