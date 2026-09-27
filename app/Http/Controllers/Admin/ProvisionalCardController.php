<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue for cards a scan created.
 *
 * A provisional row is usable by whoever scanned it and hidden from browse,
 * search, pricing and the sitemap. This is where it becomes real or goes away.
 *
 * Ordered by scan count, because that is the demand signal: fifty people
 * reaching the same row is a card worth adding properly, and one person's blurry
 * photo of a sleeve is not.
 *
 * Rejection is the careful half. catalog_items cascades to collection_items, so
 * deleting a row somebody owns would silently strip it from their collection —
 * the same rule the rehash command follows: rows a real person created are
 * merged, never dropped.
 */
class ProvisionalCardController extends Controller
{
    public function index(Request $request): Response
    {
        $cards = CatalogItem::query()
            ->where('is_provisional', true)
            ->with(['set:id,name,is_provisional', 'productLine:id,name,slug,is_provisional', 'provisionalBy:id,name'])
            // Most-scanned first: the queue should lead with what people are
            // actually holding, not with whatever was scanned most recently.
            ->orderByDesc('provisional_scans')
            ->orderByDesc('provisional_at')
            ->paginate(40)
            ->through(fn (CatalogItem $card) => [
                'id' => $card->id,
                'name' => $card->name,
                'number' => $card->number,
                'set' => $card->set?->name,
                'brand' => $card->productLine?->name,
                'new_brand' => (bool) $card->productLine?->is_provisional,
                'new_set' => (bool) $card->set?->is_provisional,
                'image_url' => $card->primary_image_path,
                'scans' => $card->provisional_scans,
                'read' => $card->provisional_read,
                'found_by' => $card->provisionalBy?->name,
                'found_at' => $card->provisional_at?->toIso8601String(),
                // Shown because it decides whether this row can be deleted at all.
                'owners' => $this->owners($card),
            ]);

        return Inertia::render('admin/provisional-cards', [
            'cards' => $cards,
            'total' => CatalogItem::where('is_provisional', true)->count(),
        ]);
    }

    /**
     * Make the row real.
     *
     * Its set and brand come with it: a confirmed card whose set is still
     * quarantined has a URL nobody can reach and no place in browse.
     */
    public function confirm(CatalogItem $catalogItem): RedirectResponse
    {
        DB::transaction(function () use ($catalogItem) {
            $catalogItem->forceFill([
                'is_provisional' => false,
                'provisional_read' => null,
            ])->save();

            Set::where('id', $catalogItem->set_id)->where('is_provisional', true)
                ->update(['is_provisional' => false]);

            ProductLine::where('id', $catalogItem->product_line_id)->where('is_provisional', true)
                ->update(['is_provisional' => false]);
        });

        return back()->with('success', "Confirmed “{$catalogItem->name}”. It is now browsable and will be priced.");
    }

    /**
     * Delete the row — only when nobody is holding it.
     *
     * catalog_items cascades to collection_items and wishlist_items, so a blind
     * delete here takes a card out of somebody's collection without telling them.
     * When it is owned, the admin is sent to merge instead.
     */
    public function reject(CatalogItem $catalogItem): RedirectResponse
    {
        $owners = $this->owners($catalogItem);

        if ($owners > 0) {
            return back()->withErrors([
                'reject' => "{$owners} person(s) hold this card. Merge it into the right card instead — deleting would remove it from their collection.",
            ]);
        }

        $setId = $catalogItem->set_id;
        $lineId = $catalogItem->product_line_id;
        $name = $catalogItem->name;

        DB::transaction(function () use ($catalogItem, $setId, $lineId) {
            $catalogItem->delete();
            $this->cleanUpEmptyProvisionalParents($setId, $lineId);
        });

        return back()->with('success', "Removed “{$name}”.");
    }

    /**
     * Fold the row into the card it should have matched.
     *
     * The route for a scan that produced a near-duplicate: the sale data and the
     * holdings are real, the row is not. Holdings move; a duplicate holding is
     * collapsed rather than dropped, because either row may be the one the person
     * actually edited.
     */
    public function merge(Request $request, CatalogItem $catalogItem): RedirectResponse
    {
        $data = $request->validate([
            'into' => ['required', 'integer', 'exists:catalog_items,id', 'different:'.$catalogItem->id],
        ]);

        $target = CatalogItem::findOrFail($data['into']);

        if ($target->id === $catalogItem->id) {
            return back()->withErrors(['into' => 'A card cannot be merged into itself.']);
        }

        DB::transaction(function () use ($catalogItem, $target) {
            // User data follows the merge. Where the person already holds the
            // target, their two rows are added together rather than one winning.
            foreach (CollectionItem::where('catalog_item_id', $catalogItem->id)->get() as $held) {
                $existing = CollectionItem::where('catalog_item_id', $target->id)
                    ->where('user_id', $held->user_id)
                    ->where('collection_id', $held->collection_id)
                    ->where('condition', $held->condition)
                    ->first();

                if ($existing) {
                    $existing->increment('quantity', (int) $held->quantity);
                    $held->delete();

                    continue;
                }

                $held->forceFill(['catalog_item_id' => $target->id])->save();
            }

            WishlistItem::where('catalog_item_id', $catalogItem->id)->get()
                ->each(function (WishlistItem $want) use ($target) {
                    $clash = WishlistItem::where('catalog_item_id', $target->id)
                        ->where('user_id', $want->user_id)->exists();

                    $clash ? $want->delete() : $want->forceFill(['catalog_item_id' => $target->id])->save();
                });

            // Comps and values are NOT moved. They were gathered against a number
            // that may have been misread, which is the mistake being undone —
            // carrying them over would move the bad price onto a good card.
            SaleObservation::where('catalog_item_id', $catalogItem->id)->delete();
            MarketValue::where('catalog_item_id', $catalogItem->id)->delete();

            $setId = $catalogItem->set_id;
            $lineId = $catalogItem->product_line_id;

            $catalogItem->delete();
            $this->cleanUpEmptyProvisionalParents($setId, $lineId);
        });

        return back()->with('success', "Merged into “{$target->name}”.");
    }

    /** How many people hold or want this card. */
    protected function owners(CatalogItem $card): int
    {
        return CollectionItem::where('catalog_item_id', $card->id)->distinct('user_id')->count('user_id')
            + WishlistItem::where('catalog_item_id', $card->id)->distinct('user_id')->count('user_id');
    }

    /**
     * Drop a provisional set or brand once the last card leaves it.
     *
     * Only provisional ones, and only when empty — a real set with no cards is
     * somebody's work in progress, not litter.
     */
    protected function cleanUpEmptyProvisionalParents(?int $setId, ?int $lineId): void
    {
        if ($setId && ! CatalogItem::where('set_id', $setId)->exists()) {
            Set::where('id', $setId)->where('is_provisional', true)->delete();
        }

        if ($lineId && ! CatalogItem::where('product_line_id', $lineId)->exists()) {
            ProductLine::where('id', $lineId)->where('is_provisional', true)->delete();
        }
    }
}
