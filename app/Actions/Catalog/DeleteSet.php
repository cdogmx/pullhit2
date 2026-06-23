<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Support\Facades\DB;

/**
 * Delete a set and every card in it. catalog_items.set_id is nullOnDelete, so the
 * items are removed explicitly first (otherwise they'd be orphaned with a null
 * set); their dependents — market values, sale observations, value snapshots,
 * collection/wishlist items, edit suggestions, scan fingerprints — cascade at the
 * DB level. Wrapped in a transaction so a set is never half-deleted.
 */
class DeleteSet
{
    public function __invoke(Set $set): void
    {
        DB::transaction(function () use ($set) {
            CatalogItem::where('set_id', $set->id)->delete();
            $set->delete();
        });
    }
}
