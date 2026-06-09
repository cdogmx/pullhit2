<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;

/**
 * Loads a catalog_item for its detail view: core relations plus every printing
 * of the same card (the base_key group, including itself) so the page can list
 * the card's variants. Read-only; shared by the web + API show controllers.
 */
class ShowCatalogItem
{
    public function __invoke(CatalogItem $item): CatalogItem
    {
        // All priced states for this item (raw + graded), with grading company.
        $item->load(['vertical', 'productLine', 'set', 'marketValues.gradingCompany']);

        // Sibling printings, each with its headline value for the printings list.
        $item->setRelation(
            'variants',
            $item->variants()
                ->with('defaultMarketValue')
                ->orderBy('number')
                ->orderBy('id')
                ->get(),
        );

        return $item;
    }
}
