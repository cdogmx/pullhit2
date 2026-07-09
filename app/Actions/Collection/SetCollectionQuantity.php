<?php

namespace App\Actions\Collection;

use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Set the quantity a user owns of a specific (collection, card, priced-state) to
 * an exact target — the "how many do I have" model. Keeps the cost-basis lots in
 * sync with the invariant quantity = Σ lots: an increase adds a lot for the
 * delta (at the given unit cost), a decrease trims lots newest-first, and zero
 * removes the holding entirely.
 */
class SetCollectionQuantity
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public function __invoke(User $user, CatalogItem $item, array $attrs, int $target): ?CollectionItem
    {
        $graded = ($attrs['grading_company_id'] ?? null) !== null;
        $collectionId = $attrs['collection_id'] ?? $user->defaultCollection()->id;
        $target = max(0, $target);

        return DB::transaction(function () use ($user, $item, $attrs, $graded, $collectionId, $target) {
            $holding = $user->collectionItems()->firstOrCreate(
                [
                    'collection_id' => $collectionId,
                    'catalog_item_id' => $item->id,
                    'condition' => $graded ? null : ($attrs['condition'] ?? null),
                    'grading_company_id' => $attrs['grading_company_id'] ?? null,
                    'grade' => $graded ? ($attrs['grade'] ?? null) : null,
                ],
                ['quantity' => 0],
            );

            $current = (int) $holding->quantity;

            if ($target === 0) {
                $holding->delete(); // lots cascade

                return null;
            }

            if ($target > $current) {
                $holding->acquisitionLots()->create([
                    'quantity' => $target - $current,
                    'unit_cost' => (int) ($attrs['unit_cost'] ?? 0),
                    'fees' => 0,
                    'acquired_at' => $attrs['acquired_at'] ?? Carbon::now()->toDateString(),
                    'source' => $attrs['source'] ?? null,
                ]);
            } elseif ($target < $current) {
                $this->trimLots($holding, $current - $target);
            }

            $holding->update(['quantity' => $target]);

            return $holding;
        });
    }

    /** Remove `$remove` copies worth of lots, newest first (splitting the boundary lot). */
    private function trimLots(CollectionItem $holding, int $remove): void
    {
        foreach ($holding->acquisitionLots()->latest('id')->get() as $lot) {
            if ($remove <= 0) {
                break;
            }

            if ($lot->quantity <= $remove) {
                $remove -= $lot->quantity;
                $lot->delete();
            } else {
                $lot->decrement('quantity', $remove);
                $remove = 0;
            }
        }
    }
}
