<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;

/**
 * Update editable fields on a holding (quantity correction, for-sale flag,
 * notes). Cost basis is managed via acquisition lots, not here.
 */
class UpdateCollectionItem
{
    /** @param  array<string, mixed>  $attrs */
    public function __invoke(CollectionItem $item, array $attrs): CollectionItem
    {
        if (array_key_exists('quantity', $attrs)) {
            $item->quantity = max(0, (int) $attrs['quantity']);
        }
        if (array_key_exists('is_for_sale', $attrs)) {
            $item->is_for_sale = (bool) $attrs['is_for_sale'];
        }
        if (array_key_exists('notes', $attrs)) {
            $item->notes = $attrs['notes'];
        }

        $item->save();

        return $item;
    }
}
