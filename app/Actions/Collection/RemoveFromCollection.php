<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;

/**
 * Remove a holding (and its acquisition lots, via cascade) from a collection.
 */
class RemoveFromCollection
{
    public function __invoke(CollectionItem $item): void
    {
        $item->delete();
    }
}
