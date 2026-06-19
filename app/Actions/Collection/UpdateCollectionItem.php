<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;
use Illuminate\Support\Facades\DB;

/**
 * Update editable fields on a holding (quantity, for-sale flag, notes, folder,
 * the priced state, and which collection it lives in). Cost basis is managed via
 * acquisition lots, not here.
 *
 * Changing the state (condition/grade) or moving collections can land the holding
 * on the same (collection, card, state) as an existing one — in that case the two
 * merge: lots + quantity fold into the survivor and the edited row is removed.
 */
class UpdateCollectionItem
{
    /** @param  array<string, mixed>  $attrs */
    public function __invoke(CollectionItem $item, array $attrs): CollectionItem
    {
        return DB::transaction(function () use ($item, $attrs) {
            // Priced state — a graded copy has no raw condition; a raw copy has
            // no grade. Only touch state when one of its fields was submitted.
            if (array_intersect_key($attrs, array_flip(['condition', 'grading_company_id', 'grade']))) {
                $companyId = $attrs['grading_company_id'] ?? null;
                $graded = $companyId !== null;

                $item->grading_company_id = $companyId;
                $item->grade = $graded ? ($attrs['grade'] ?? null) : null;
                $item->condition = $graded ? null : ($attrs['condition'] ?? $item->condition?->value);
            }

            if (array_key_exists('quantity', $attrs)) {
                $item->quantity = max(0, (int) $attrs['quantity']);
            }
            if (array_key_exists('is_for_sale', $attrs)) {
                $item->is_for_sale = (bool) $attrs['is_for_sale'];
            }
            if (array_key_exists('notes', $attrs)) {
                $item->notes = $attrs['notes'];
            }
            if (array_key_exists('folder', $attrs)) {
                $item->folder = $attrs['folder'];
            }
            if (array_key_exists('collection_id', $attrs)) {
                $item->collection_id = (int) $attrs['collection_id'];
            }

            if ($survivor = $this->findDuplicate($item)) {
                // Fold this holding into the existing one of the same state.
                $item->acquisitionLots()->update(['collection_item_id' => $survivor->id]);
                $survivor->increment('quantity', $item->quantity);
                $item->delete();

                return $survivor->refresh();
            }

            $item->save();

            return $item;
        });
    }

    /** Another of the user's holdings with the same (collection, card, state), if any. */
    private function findDuplicate(CollectionItem $item): ?CollectionItem
    {
        $query = CollectionItem::where('user_id', $item->user_id)
            ->where('collection_id', $item->collection_id)
            ->where('catalog_item_id', $item->catalog_item_id)
            ->whereKeyNot($item->getKey());

        $item->condition
            ? $query->where('condition', $item->condition->value)
            : $query->whereNull('condition');

        $item->grading_company_id
            ? $query->where('grading_company_id', $item->grading_company_id)
            : $query->whereNull('grading_company_id');

        $item->grade !== null
            ? $query->where('grade', $item->grade)
            : $query->whereNull('grade');

        return $query->first();
    }
}
