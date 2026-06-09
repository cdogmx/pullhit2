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
        $item->load(['vertical', 'productLine', 'set']);

        $item->setRelation(
            'variants',
            $item->variants()
                ->orderBy('number')
                ->orderBy('id')
                ->get(),
        );

        return $item;
    }
}
