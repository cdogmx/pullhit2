<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Support\Scanning\IdentifiedCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Put a scanned card nobody could match into the catalog, quarantined.
 *
 * A scan that matches nothing is a dead end: the card is real, somebody is
 * holding it, and they cannot log it. This creates the row so they can — and
 * flags it, because a vision read is not a catalog entry.
 *
 * Why the flag rather than a plain insert. identity_hash is a function of the
 * NAME, so a row made from a read of "Charizard EX" never matches the official
 * importer's "Charizard ex" and becomes a permanent duplicate — the reason
 * RehashCatalogCommand exists. A misread number is worse: comps match on set and
 * number, so the row quietly collects sales for a different card, which is how a
 * $4 card came to be priced at $53. Quarantine keeps both mistakes local to the
 * person who made them until somebody confirms the row.
 *
 * A brand we do not hold yet arrives as a product line and a set too. Both are
 * created provisionally. There is one vertical (`tcg`) and the attribute schema
 * hangs off it, so a new brand needs no registry change — but it does need a
 * human before it appears in navigation.
 */
class CreateProvisionalCard
{
    public function __construct(protected CreateCatalogItem $create) {}

    /**
     * Returns the card, or null when the read is too thin to make a row from.
     */
    public function __invoke(IdentifiedCard $card, ?User $user = null): ?CatalogItem
    {
        $name = trim((string) $card->name);

        // A name is the one thing a row cannot be made without: it feeds the
        // identity hash, the slug and the URL. No name, no row — the scan still
        // returns its read, and the person can report it by hand.
        if ($name === '') {
            return null;
        }

        $vertical = Vertical::where('slug', 'tcg')->first();

        if (! $vertical) {
            return null;
        }

        $line = $this->productLine($vertical, $card);
        $set = $this->set($line, $card);

        // language and variant are required facets for a tcg single, so a read
        // that omits them still has to state something. "normal" is the base
        // printing and the value every importer uses when a source is silent —
        // and because it is variant-defining, a later confirmed foil becomes its
        // own row rather than overwriting this one.
        $attributes = array_filter([
            'language' => $card->language ?: 'en',
            'variant' => in_array($card->variant, ['normal', 'holo', 'reverse_holo', 'foil'], true)
                ? $card->variant
                : 'normal',
            'edition' => $card->edition,
        ], fn ($v) => $v !== null && $v !== '');

        $item = ($this->create)(
            vertical: $vertical,
            productLine: $line,
            set: $set,
            itemType: ItemType::Single,
            name: $name,
            number: $card->number ?: null,
            attributes: $attributes,
        );

        // Idempotent by identity_hash, so a second scan of the same card lands on
        // this row rather than making another. Count the scans — fifty people
        // reaching the same row is the signal for what to confirm first — but
        // never re-flag a row a reviewer has already confirmed.
        if ($item->wasRecentlyCreated || $item->is_provisional) {
            $item->forceFill([
                'is_provisional' => true,
                'provisional_by' => $item->provisional_by ?? $user?->id,
                'provisional_read' => $item->provisional_read ?? $this->read($card),
                'provisional_at' => $item->provisional_at ?? Carbon::now(),
                'provisional_scans' => (int) $item->provisional_scans + 1,
            ])->save();
        }

        return $item;
    }

    /**
     * The brand, created provisionally when we do not hold it.
     *
     * Matched on slug so "Star Wars Unlimited" and "star-wars-unlimited" are the
     * same brand however the read spelled it.
     */
    protected function productLine(Vertical $vertical, IdentifiedCard $card): ProductLine
    {
        $brand = trim((string) ($card->productLine ?? ''));

        if ($brand === '') {
            // Nothing said which game it is. A provisional holding pen is better
            // than filing it under whichever brand happens to be first.
            $brand = 'Unidentified';
        }

        $slug = Str::slug($brand);
        $existing = ProductLine::where('vertical_id', $vertical->id)->where('slug', $slug)->first();

        if ($existing) {
            return $existing;
        }

        $line = new ProductLine;
        $line->forceFill([
            'vertical_id' => $vertical->id,
            'slug' => $slug,
            'name' => $brand,
            'is_provisional' => true,
        ])->save();

        return $line;
    }

    /**
     * The set, created provisionally when we do not hold it.
     *
     * A set is needed because comps match on set and number, and because browse
     * has nowhere to put a card without one. An unnamed set becomes a per-brand
     * holding pen rather than null, so these rows stay findable as a group.
     */
    protected function set(ProductLine $line, IdentifiedCard $card): Set
    {
        $name = trim((string) ($card->setName ?? '')) ?: 'Unsorted';
        $slug = Str::slug($line->slug.'-'.$name);

        $existing = Set::where('product_line_id', $line->id)
            ->where(fn ($q) => $q->where('slug', $slug)->orWhere('name', $name))
            ->first();

        if ($existing) {
            return $existing;
        }

        $set = new Set;
        $set->forceFill([
            'product_line_id' => $line->id,
            'slug' => $slug,
            'name' => $name,
            'code' => $card->setCode ?: null,
            'language' => $card->language ?: 'en',
            'is_provisional' => true,
        ])->save();

        return $set;
    }

    /**
     * The read as it was, so a reviewer can judge the row against it.
     *
     * @return array<string, mixed>
     */
    protected function read(IdentifiedCard $card): array
    {
        return array_filter([
            'name' => $card->name,
            'number' => $card->number,
            'set_name' => $card->setName,
            'set_code' => $card->setCode,
            'product_line' => $card->productLine ?? null,
            'language' => $card->language,
            'variant' => $card->variant,
            'edition' => $card->edition,
            'confidence' => $card->confidence,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
