<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles the evidence behind one priced state's value: the computed snapshot,
 * the contributing sale_observations (incl. outliers, newest first), and a
 * venue→count source breakdown. Powers the price-breakdown drawer.
 */
class GetPricedStateBreakdown
{
    /**
     * @return array{value: MarketValue, observations: Collection<int, SaleObservation>, sources: array<string, int>}|null
     */
    public function __invoke(CatalogItem $item, string $stateKey): ?array
    {
        $value = $item->marketValues()->where('state_key', $stateKey)->first();
        if (! $value) {
            return null;
        }

        $cutoff = Carbon::now()->subDays((int) config('valuation.lookback_days'));

        $query = $item->saleObservations()->where('observed_at', '>=', $cutoff);
        $value->condition === null
            ? $query->whereNull('condition')
            : $query->where('condition', $value->condition->value);
        $value->grading_company_id === null
            ? $query->whereNull('grading_company_id')
            : $query->where('grading_company_id', $value->grading_company_id);
        $value->grade === null
            ? $query->whereNull('grade')
            : $query->where('grade', $value->grade);

        $observations = $query->orderByDesc('observed_at')->get();

        $sources = $observations
            ->groupBy(fn (SaleObservation $o) => $o->venue->value)
            ->map->count()
            ->all();

        return ['value' => $value, 'observations' => $observations, 'sources' => $sources];
    }
}
