<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;
use Illuminate\Support\Str;

/**
 * How a SEALED product is searched for on eBay, and whether a returned title is
 * really that product. Sealed has nothing in common with singles here — no
 * collector number, no condition/grade axis, and the big errors come from
 * variant cross-contamination (a Case read as a single box, a Pokémon Center
 * exclusive read as the regular SKU) rather than from the wrong card.
 *
 * One home for it because four callers need the same answer: the sold-comp
 * source and classifier, the card page's live listings, and the for-sale ask
 * ingest. They used to agree on none of it — the listings paths searched with
 * singles wording ("… Near Mint") and filtered on language alone.
 */
final class SealedSearch
{
    /**
     * Sealed products sell under natural retail wording, so we search that way:
     * "{Game} - {Set} - {Product}", e.g. "Pokemon - Black Bolt - Booster Bundle".
     *  - The game name is prefixed (accent-stripped, so "Pokémon" → "Pokemon")
     *    to disambiguate generic product names across lines — a product named
     *    just "Booster Box" is meaningless without it.
     *  - Game and set are folded in ONLY when the product name doesn't already
     *    carry them; many sealed SKUs are named "Black Bolt Booster Bundle" (or
     *    "Disney Lorcana: …") already, and repeating just adds noise.
     *  - The set CODE is dropped: no real sealed listing title contains "(BLK)".
     */
    public static function query(CatalogItem $item): string
    {
        $name = $item->name;
        $haystack = mb_strtolower($name);
        $parts = [];

        if ($line = $item->productLine) {
            $game = trim(Str::ascii($line->name));
            if ($game !== '' && ! str_contains($haystack, mb_strtolower($game))) {
                $parts[] = $game;
            }
        }

        $set = $item->set;
        if ($set && ! str_contains($haystack, mb_strtolower($set->name))) {
            $parts[] = $set->name;
        }

        $parts[] = $name;

        return implode(' - ', array_values(array_filter($parts, fn ($p) => $p !== '')));
    }

    /**
     * Why a listing title isn't this sealed product, or null when it passes.
     *
     * `$requireSet` additionally demands a distinctive word from the set name.
     * The sold path leaves it off — its scraped search is already pinned to one
     * set, and the gate would drop legitimate comps whose title abbreviates the
     * set. The card-page listings turn it on: the Browse API happily returns a
     * different set's booster box for the same game, and on a page devoted to
     * one product that reads as a plain wrong answer.
     */
    public static function rejectReason(CatalogItem $item, string $title, bool $requireSet = false): ?string
    {
        $lower = mb_strtolower($title);
        $name = mb_strtolower($item->name);

        // Blocklist — but never reject on a term the product legitimately IS. The
        // single-card blocklist contains "bundle" (mystery-lot bundles); without
        // this guard it would kill every real "Booster Bundle" sealed comp.
        foreach ((array) config('valuation.ebay.blocklist', []) as $bad) {
            if (! str_contains($name, $bad) && str_contains($lower, $bad)) {
                return "blocklisted term “{$bad}”";
            }
        }

        // Opened / empty / packs-removed boxes are collectible empties, not a
        // sealed sale — reject by title (don't rely on them being price outliers).
        // "unopened" is safe: the word boundary won't match inside it.
        if (preg_match('/\b(empty|opened|no packs?|box only|packs? removed|no cards?|inserts only)\b/', $lower)) {
            return 'opened / empty box';
        }

        // Multi-product lots — an explicit "lot", a quantity multiplier ("2x" /
        // "x3"), or a "+"-joined multi-product title ("ETB + booster box").
        if (preg_match('/\b(lot|lots)\b/', $lower)
            || preg_match('/\b([2-9]|\d{2,})\s*x\b|\bx\s*([2-9]|\d{2,})\b/', $lower)
            || str_contains($lower, ' + ')) {
            return 'multi-product lot';
        }

        // A COUNT of the product's OWN unit is a lot ("2 booster boxes", "3
        // bundles", "6 loose packs" for a pack product). But a count of a
        // sub-unit the product legitimately CONTAINS — a Booster Bundle's "6
        // Packs", an ETB's "9 Packs", a Booster Box's "36 Packs" — is its
        // contents, not a lot. So the counted noun is keyed to this product's
        // own type rather than any product word.
        $unit = self::unitPattern($item);
        if ($unit !== null && preg_match('/\b([2-9]|\d{2,})\s*(?:'.$unit.')\b/', $lower)) {
            return 'multi-quantity lot';
        }

        // The listing must describe this exact sealed variant.
        if (! self::variantMatches($item, $lower)) {
            return 'wrong sealed type / variant';
        }

        // The product name's first token must appear (e.g. "crown", "151").
        $primary = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok($item->name, ' ')));

        if ($primary !== '' && ! str_contains((string) preg_replace('/[^a-z0-9]/', '', $lower), $primary)) {
            return 'title does not name this product';
        }

        if ($requireSet && ! self::namesTheSet($item, $lower)) {
            return 'title does not name this set';
        }

        return null;
    }

    /** Convenience predicate over {@see rejectReason()}. */
    public static function matches(CatalogItem $item, string $title, bool $requireSet = false): bool
    {
        return self::rejectReason($item, $title, $requireSet) === null;
    }

    /**
     * Does the title carry a distinctive word from the set name? Short and
     * generic words ("the", "of", "set") are ignored, so "Attack of the Vine!"
     * is satisfied by "attack" or "vine" but not by "of". A set whose name is
     * entirely generic yields no test at all and passes — better than rejecting
     * every listing for it.
     */
    private static function namesTheSet(CatalogItem $item, string $lower): bool
    {
        $set = $item->set?->name;

        if ($set === null || $set === '') {
            return true;
        }

        // The product name already carrying the set means the name-token gate
        // above has covered it.
        if (str_contains(mb_strtolower($item->name), mb_strtolower($set))) {
            return true;
        }

        $stop = ['the', 'of', 'and', 'a', 'an', 'set', 'card', 'cards', 'tcg'];
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($set)) ?: [],
            fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, $stop, true),
        ));

        if ($tokens === []) {
            return true; // nothing distinctive to test on
        }

        foreach ($tokens as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a sealed listing's variant agrees with the product: Case ⇔ Case,
     * Pokémon Center ⇔ PC, Plus ⇔ Plus, and the sealed type's keyword is present
     * (so an ETB listing never matches a Booster Box product, etc.).
     */
    private static function variantMatches(CatalogItem $item, string $lower): bool
    {
        $name = mb_strtolower($item->name);

        $itemCase = str_contains($name, 'case');
        $listCase = (bool) preg_match('/\bcase\b/', $lower);
        if ($itemCase !== $listCase) {
            return false;
        }

        $itemPc = str_contains($name, 'pokemon center') || str_contains($name, 'pokémon center');
        $listPc = (bool) preg_match('/pok[eé]mon\s+center|\bpc\b/', $lower);
        if ($itemPc !== $listPc) {
            return false;
        }

        $itemPlus = (bool) preg_match('/\bplus\b/', $name);
        $listPlus = (bool) preg_match('/\bplus\b/', $lower);
        if ($itemPlus !== $listPlus) {
            return false;
        }

        $stored = $item->getAttribute('attributes')['sealed_type'] ?? null;
        if (self::typePresent($stored, $lower)) {
            return true;
        }

        // Fall back to the type the PRODUCT NAME itself states. A sealed product
        // mis-typed in admin (e.g. a "First Partner Illustration Collection"
        // saved as booster_box, the dialog's default) would otherwise reject
        // every real comp — its own name's type word is authoritative.
        $inferred = self::inferType($name);

        return $inferred !== null && $inferred !== $stored && self::typePresent($inferred, $lower);
    }

    /**
     * A regex alternation of the product's OWN unit noun(s) — the thing that,
     * when counted ≥2, means a multi-quantity lot. Keyed to the sealed type (or
     * the type the name states), so a Booster Bundle's "6 Packs" (a sub-unit it
     * contains) isn't mistaken for a lot, while "2 Bundles" is. Null when the
     * type is unknown — then only the lot/×N/"+" signals apply.
     */
    private static function unitPattern(CatalogItem $item): ?string
    {
        $type = $item->getAttribute('attributes')['sealed_type'] ?? null;
        $type = $type ?: self::inferType(mb_strtolower($item->name));

        return match ($type) {
            'booster_box' => 'booster boxes|booster box|boxes',
            'booster_box_case' => 'cases?',
            'booster_pack', 'sleeved_booster_pack' => 'packs?',
            'booster_bundle', 'bundle' => 'bundles?',
            'elite_trainer_box' => 'etbs?|elite trainer boxes?',
            'tin' => 'tins?',
            'collection' => 'collections?',
            'blister', 'checklane' => 'blisters?|checklanes?',
            'build_and_battle' => 'build\s*&?\s*battle boxes?|kits?',
            default => null,
        };
    }

    /** The sealed type a product name states, or null if it names none. */
    public static function inferType(string $name): ?string
    {
        return match (true) {
            str_contains($name, 'elite trainer box') || (bool) preg_match('/\betb\b/', $name) => 'elite_trainer_box',
            str_contains($name, 'booster box') => 'booster_box',
            str_contains($name, 'build') && str_contains($name, 'battle') => 'build_and_battle',
            str_contains($name, 'bundle') => 'booster_bundle',
            str_contains($name, 'blister') || str_contains($name, 'checklane') => 'blister',
            (bool) preg_match('/\btin\b/', $name) => 'tin',
            str_contains($name, 'collection') => 'collection',
            str_contains($name, 'sleeved') => 'sleeved_booster_pack',
            str_contains($name, 'booster pack') || (bool) preg_match('/\bpack\b/', $name) => 'booster_pack',
            default => null,
        };
    }

    /** Whether the listing names the product's sealed type (ETB abbreviations allowed). */
    private static function typePresent(?string $type, string $lower): bool
    {
        return match ($type) {
            'elite_trainer_box' => str_contains($lower, 'elite trainer box') || (bool) preg_match('/\betb\b/', $lower),
            'booster_box', 'booster_box_case' => str_contains($lower, 'booster box'),
            'booster_pack' => str_contains($lower, 'booster pack') || (bool) preg_match('/\bpack\b/', $lower),
            'sleeved_booster_pack' => str_contains($lower, 'sleeved'),
            'booster_bundle', 'bundle' => str_contains($lower, 'bundle'),
            'build_and_battle' => str_contains($lower, 'build') && str_contains($lower, 'battle'),
            'tin' => (bool) preg_match('/\btin\b/', $lower),
            'collection' => str_contains($lower, 'collection'),
            'blister', 'checklane' => str_contains($lower, 'blister') || str_contains($lower, 'checklane'),
            default => true, // unknown/other type — don't over-restrict
        };
    }
}
