<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Support\Valuation\PricedStateKey;

/**
 * Recompute market_values for every distinct priced state present in a catalog
 * item's observations (each raw condition + each graded company/grade), and
 * prune snapshots for states that no longer have any observations.
 */
class RecomputeCatalogItem
{
    public function __construct(
        protected RecomputeMarketValue $recomputeState,
    ) {}

    public function __invoke(CatalogItem $item): int
    {
        $states = $item->saleObservations()
            ->select('condition', 'grading_company_id', 'grade')
            ->distinct()
            ->get();

        $keptKeys = [];
        foreach ($states as $state) {
            ($this->recomputeState)(
                $item,
                $state->condition?->value,
                $state->grading_company_id,
                $state->grade,
            );
            $keptKeys[] = PricedStateKey::for($state->condition?->value, $state->grading_company_id, $state->grade);
        }

        // Drop snapshots for states with no remaining observations (e.g. graded
        // synthetic placeholders cleared when real eBay comps arrive).
        $item->marketValues()->whereNotIn('state_key', $keptKeys)->delete();

        return $states->count();
    }
}
