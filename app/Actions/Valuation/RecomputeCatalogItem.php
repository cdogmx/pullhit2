<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;

/**
 * Recompute market_values for every distinct priced state present in a catalog
 * item's observations (each raw condition + each graded company/grade).
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

        foreach ($states as $state) {
            ($this->recomputeState)(
                $item,
                $state->condition?->value,
                $state->grading_company_id,
                $state->grade,
            );
        }

        return $states->count();
    }
}
