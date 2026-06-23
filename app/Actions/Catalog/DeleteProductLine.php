<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Support\Facades\DB;

/**
 * Delete a brand (product line) and its entire subtree — every set and every
 * card. Items are removed by product_line_id (covers any whose set was already
 * null); each item's dependents cascade at the DB level, then the sets, then the
 * line. Transactional so a brand is never left partially deleted.
 */
class DeleteProductLine
{
    public function __invoke(ProductLine $productLine): void
    {
        DB::transaction(function () use ($productLine) {
            CatalogItem::where('product_line_id', $productLine->id)->delete();
            $productLine->sets()->delete();
            $productLine->delete();
        });
    }
}
