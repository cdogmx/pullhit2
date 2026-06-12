<?php

namespace App\Actions\Collection;

use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add owned copies of a catalog item to a user's collection. Finds-or-creates
 * the (user, card, priced-state) row so repeated adds of the same state merge
 * into one holding, and records the acquisition as a cost-basis lot.
 */
class AddToCollection
{
    public function __construct(
        protected AddAcquisitionLot $addLot,
    ) {}

    /** @param  array<string, mixed>  $attrs */
    public function __invoke(User $user, CatalogItem $item, array $attrs): CollectionItem
    {
        $graded = ($attrs['grading_company_id'] ?? null) !== null;

        return DB::transaction(function () use ($user, $item, $attrs, $graded) {
            $collectionItem = $user->collectionItems()->firstOrCreate(
                [
                    'catalog_item_id' => $item->id,
                    // A graded copy has no raw condition; a raw copy has no grade.
                    'condition' => $graded ? null : ($attrs['condition'] ?? null),
                    'grading_company_id' => $attrs['grading_company_id'] ?? null,
                    'grade' => $graded ? ($attrs['grade'] ?? null) : null,
                ],
                [
                    'quantity' => 0, // the lot below sets the real quantity
                    'is_for_sale' => $attrs['is_for_sale'] ?? false,
                    'notes' => $attrs['notes'] ?? null,
                    'folder' => $attrs['folder'] ?? null,
                ],
            );

            ($this->addLot)($collectionItem, [
                'quantity' => $attrs['quantity'] ?? 1,
                'unit_cost' => $attrs['unit_cost'] ?? 0,
                'fees' => $attrs['fees'] ?? 0,
                'acquired_at' => $attrs['acquired_at'] ?? null,
                'source' => $attrs['source'] ?? null,
            ]);

            return $collectionItem->refresh();
        });
    }
}
