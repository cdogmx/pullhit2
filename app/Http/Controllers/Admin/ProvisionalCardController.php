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
use App\Support\Catalog\ItemIdentity;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function __construct(protected VerticalRegistry $registry) {}

    public function index(Request $request): Response
    {
        $cards = CatalogItem::query()
            ->where('is_provisional', true)
            ->with(['set:id,name,is_provisional', 'productLine:id,name,slug,is_provisional', 'provisionalBy:id,name', 'vertical:id,slug'])
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
                'vertical' => $card->vertical?->slug,
                'attributes' => $card->getAttribute('attributes') ?? [],
                // Which facets this vertical actually has, so the form offers
                // parallel/autograph on a collectible and variant/edition on a
                // game single rather than one hardcoded set of fields.
                'facets' => $this->facets($card),
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
     * Correct a row before confirming it.
     *
     * The read is a guess, and the queue exists because guesses need checking —
     * so the reviewer has to be able to fix one rather than only accept or
     * reject it. Two scans of Topps produced brands called "Topps Chrome Pixar"
     * and "Disney Topps Chrome" for one maker and two sets; naming that properly
     * is an edit, not a rejection.
     *
     * Facets go through the vertical registry, so a collectible is judged
     * against parallel/autograph and a game single against variant/edition, and
     * neither can be given the other's vocabulary.
     */
    public function update(Request $request, CatalogItem $catalogItem, ItemIdentity $identity): RedirectResponse
    {
        abort_unless($catalogItem->is_provisional, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:50'],
            // Renaming is allowed only while the set or brand is itself
            // provisional: a real set is shared by other cards, and this page is
            // not the place to rename one out from under them.
            'set_name' => ['nullable', 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
        ]);

        $catalogItem->loadMissing(['vertical', 'productLine', 'set']);

        try {
            $attributes = $this->registry->validate(
                $catalogItem->vertical->slug,
                $catalogItem->item_type->value,
                // Blank inputs mean "no value", not an empty string: an empty
                // enum would fail validation and an empty parallel would read as
                // a printing called "".
                array_filter(
                    $data['attributes'] ?? $catalogItem->getAttribute('attributes') ?? [],
                    fn ($v) => $v !== null && $v !== '',
                ),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        DB::transaction(function () use ($catalogItem, $data, $attributes, $identity) {
            if (! empty($data['set_name']) && $catalogItem->set?->is_provisional) {
                $catalogItem->set->forceFill(['name' => $data['set_name']])->save();
            }

            if (! empty($data['brand_name']) && $catalogItem->productLine?->is_provisional) {
                $catalogItem->productLine->forceFill(['name' => $data['brand_name']])->save();
            }

            $catalogItem->forceFill([
                'name' => $data['name'],
                // ?? as well as ?:, because a nullable field that was not sent
                // is ABSENT from validated() rather than null — so an edit that
                // leaves the number alone would otherwise fatal.
                'number' => ($data['number'] ?? null) ?: null,
                'attributes' => $attributes,
            ])->save();

            // The hash is a function of the name, the number and the facets, so
            // an edit that does not rehash leaves the row hashed as the card it
            // used to be — and the next import inserts a duplicate instead of
            // matching it.
            $catalogItem->forceFill($identity->forItem($catalogItem->fresh()))->save();
        });

        return back()->with('success', "Updated “{$data['name']}”.");
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

    /**
     * The editable facets for this card's vertical.
     *
     * Read from the registry rather than listed here, so a facet added to a
     * vertical appears in this form without anyone remembering to update it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function facets(CatalogItem $card): array
    {
        // has(), because get() THROWS on an unregistered slug. A row carrying a
        // vertical nobody registered — a retired one, a typo, a fixture — would
        // otherwise take the whole review queue down with a 500, and this page
        // exists precisely to deal with rows that are not yet right.
        if (! $card->vertical || ! $this->registry->has($card->vertical->slug)) {
            return [];
        }

        return array_map(fn ($a) => [
            'key' => $a->key,
            'label' => $a->label,
            'type' => $a->type->value,
            'required' => $a->required,
            'options' => $a->options,
        ], $this->registry->get($card->vertical->slug)->attributesFor($card->item_type->value));
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
