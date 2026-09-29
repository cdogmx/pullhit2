<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Scanning\ScanArchive;
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
    public function __construct(
        protected CreateCatalogItem $create,
        protected ScanArchive $archive,
    ) {}

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

        $vertical = $this->vertical($card);

        if (! $vertical) {
            return null;
        }

        $line = $this->productLine($vertical, $card);
        $set = $this->set($line, $card);

        $attributes = $this->attributes($vertical, $card);

        $item = ($this->create)(
            vertical: $vertical,
            productLine: $line,
            set: $set,
            itemType: ItemType::Single,
            name: $name,
            number: $this->collectorNumber($vertical, $card),
            attributes: $attributes,
            // The scan's own crop. A card nobody has catalogued has no catalog
            // art by definition, so this is the only picture of it there is —
            // and the review queue cannot be judged without one.
            primaryImagePath: $user
                ? $this->archive->storeCardImage($user, $card->thumbnail)
                : null,
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
     * The card's collector number, with a serial number refused.
     *
     * THE most dangerous read in this category. On a collectible "014/199"
     * means the 14th of 199 copies; on a TCG single "006/025" means card 6 of a
     * 25-card set. They are written identically and mean opposite things — and
     * the number feeds identity_hash, so a serial stored here would give every
     * copy its own catalog row. 199 people scanning one card would make 199 of
     * them.
     *
     * The prompt now asks for serial and print_run separately, but a model that
     * puts one here anyway must not be believed, so the shape is refused in code
     * as well. Only for collectibles: on a TCG single N/M is exactly what the
     * field is for.
     */
    protected function collectorNumber(Vertical $vertical, IdentifiedCard $card): ?string
    {
        $number = $card->number ?: null;

        if ($number === null || $vertical->slug !== 'collectibles') {
            return $number;
        }

        return self::printRunFromSerial($number) === null ? $number : null;
    }

    /**
     * The print run inside a serial like "014/199", or null when it is not one.
     *
     * A serial's denominator is the run size and its numerator is a single copy
     * within it, so the numerator has to be no larger. That check is what keeps
     * a genuine collector number — "199/014" never appears, but "006/025" does —
     * from being mistaken for a serial.
     */
    protected static function printRunFromSerial(?string $number): ?int
    {
        if (! preg_match('#^\s*(\d{1,5})\s*/\s*(\d{1,5})\s*$#', (string) $number, $m)) {
            return null;
        }

        [$copy, $run] = [(int) $m[1], (int) $m[2]];

        return $copy >= 1 && $copy <= $run ? $run : null;
    }

    /**
     * The facets to create the row with, in the vocabulary of its vertical.
     *
     * The two vocabularies do not overlap, and CreateCatalogItem rejects a facet
     * the schema does not declare — so a collectible built with tcg's `variant`
     * would not save at all.
     *
     * @return array<string, mixed>
     */
    protected function attributes(Vertical $vertical, IdentifiedCard $card): array
    {
        $language = $card->language ?: 'en';

        if ($vertical->slug === 'collectibles') {
            // The read's "variant" is never carried over: it is a GAME notion,
            // and the first Mickey scan came back "holo" for a teal refractor.
            // `parallel` is only set when the model actually named one, so an
            // absent value means base rather than a parallel nobody saw.
            return array_filter([
                'language' => $language,
                'parallel' => $card->parallel ? trim($card->parallel) : null,
                'print_run' => $card->printRun ?: self::printRunFromSerial($card->number),
                'autograph' => $card->autograph,
                'memorabilia' => $card->memorabilia,
            ], fn ($v) => $v !== null && $v !== '');
        }

        // language and variant are required facets for a tcg single, so a read
        // that omits them still has to state something. "normal" is the base
        // printing and the value every importer uses when a source is silent —
        // and because it is variant-defining, a later confirmed foil becomes its
        // own row rather than overwriting this one.
        return array_filter([
            'language' => $language,
            'variant' => in_array($card->variant, ['normal', 'holo', 'reverse_holo', 'foil'], true)
                ? $card->variant
                : 'normal',
            'edition' => $card->edition,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Which vertical this card belongs to.
     *
     * A brand we already hold answers it outright. Otherwise the read's own
     * words decide: Topps, Panini and Upper Deck do not make trading card GAMES,
     * and filing their cards under `tcg` is how the first two scans of this kind
     * came back tagged "variant: holo" — the game vocabulary forced onto a chrome
     * refractor, wrong but valid.
     *
     * Unrecognised falls to `tcg`, which is what most scans are, and a reviewer
     * can move it.
     */
    protected function vertical(IdentifiedCard $card): ?Vertical
    {
        $brand = mb_strtolower(trim((string) ($card->productLine ?? '')));

        if ($brand !== '') {
            $known = ProductLine::where('slug', Str::slug($brand))->first();

            if ($known) {
                return Vertical::find($known->vertical_id);
            }
        }

        $haystack = $brand.' '.mb_strtolower((string) ($card->setName ?? ''));

        foreach (self::COLLECTIBLE_MAKERS as $maker) {
            if (str_contains($haystack, $maker)) {
                return Vertical::where('slug', 'collectibles')->first()
                    ?? Vertical::where('slug', 'tcg')->first();
            }
        }

        return Vertical::where('slug', 'tcg')->first();
    }

    /**
     * Makers whose cards are collectibles rather than a game.
     *
     * Deliberately a short list of manufacturers rather than a guess at subject
     * matter: "Disney" is Lorcana as often as it is Topps Chrome, and the maker
     * is the part that actually decides which schema fits.
     */
    private const COLLECTIBLE_MAKERS = [
        'topps', 'panini', 'upper deck', 'leaf', 'donruss', 'fleer', 'bowman', 'score',
    ];

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
