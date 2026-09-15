<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;
use App\Support\Catalog\CardDisplayName;
use App\Support\Catalog\StampMatcher;
use App\Support\Catalog\Subsets;

/**
 * The edition/variant/error terms that pin an eBay search to one specific
 * printing — so a 1st Edition card's comps aren't polluted with Unlimited sales
 * (and vice-versa). Shared by the sold-comp lookup and the "Shop on eBay" links.
 * Unlimited adds no positive term (sellers rarely write it); the comp classifier
 * instead rejects 1st-Edition/Shadowless listings for an Unlimited card.
 */
final class CardSearchTerms
{
    /**
     * Rarity => the words a seller writes, for the tiers worth searching on.
     * The percentages are how often 1,200 sampled sold titles carry the term.
     */
    private const RARITY_TERMS = [
        'special illustration rare' => 'Special Illustration Rare',   // 60.4%
        'illustration rare' => 'Illustration Rare',                   // 67.8%
        'shiny secret rare' => 'Shiny Secret Rare',                   // 85.6%
        'ultra rare' => 'Ultra Rare',                                 // 70.4%
        'hyper rare' => 'Hyper Rare',                                 // 59.1%
        'mega hyper rare' => 'Hyper Rare',
        'futuristic rare' => 'Futuristic Rare',
    ];

    /** Our language codes => the eBay "Language" aspect / title wording. */
    private const LANGUAGES = [
        'en' => 'English',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'zh' => 'Chinese',
        'zh-CN' => 'Chinese',
        'zh-TW' => 'Chinese',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
    ];

    /**
     * How each language shows up in a seller's title. English is absent on
     * purpose — sellers almost never write "English", so an English card is
     * identified by the ABSENCE of every other language's marker.
     */
    private const LANGUAGE_MARKERS = [
        'Japanese' => '/\bjapan(ese)?\b|\bjpn?\b|日本語/iu',
        'Korean' => '/\bkorean?\b|한국/iu',
        'Chinese' => '/\bchinese\b|中文/iu',
        'French' => '/\bfrench\b|\bfran[cç]ais\b/iu',
        'German' => '/\bgerman[y]?\b|\bdeutsch\b/iu',
        'Italian' => '/\bitalian[o]?\b/iu',
        'Spanish' => '/\bspanish\b|\bespa[nñ]ol\b/iu',
        'Portuguese' => '/\bportugu[eêé]s(e)?\b/iu',
    ];

    /** The eBay "Language" aspect value for a card ("Japanese"), or null if unknown. */
    public static function language(CatalogItem $item): ?string
    {
        $code = $item->language ?? ($item->getAttribute('attributes')['language'] ?? null);

        return self::LANGUAGES[$code] ?? null;
    }

    /**
     * The keyword that pins a search to this printing's language — null for
     * English (and unknown), where the word only shrinks the result set.
     */
    public static function languageKeyword(CatalogItem $item): ?string
    {
        $language = self::language($item);

        return ($language === null || $language === 'English') ? null : $language;
    }

    /**
     * Is this listing title the card's language? A non-English printing must say
     * so ("Japanese", "JP", 日本語); an English one must name no other language.
     * Keyword searches alone leak the wrong printing, whose prices are a
     * different market entirely.
     */
    public static function matchesLanguage(CatalogItem $item, string $title): bool
    {
        $language = self::language($item);
        if ($language === null) {
            return true; // unknown language — don't over-restrict
        }

        foreach (self::LANGUAGE_MARKERS as $marker => $pattern) {
            $found = (bool) preg_match($pattern, $title);

            if ($marker === $language) {
                if (! $found) {
                    return false; // its own language must be stated
                }
            } elseif ($found) {
                return false; // some other language's printing
            }
        }

        return true;
    }

    /**
     * The set name, when it is specific enough to narrow the search rather than
     * break it.
     *
     * Worth including: between 88% and 95% of real sold titles for a modern set
     * name it, measured across Paldean Fates, Surging Sparks, Obsidian Flames
     * and Evolving Skies. The number alone does not disambiguate a printing —
     * a Gardevoir ex sold search returned the Special Illustration Rare, whose
     * title carried no number at all, and it set the card's PSA 10 price.
     *
     * The set CODE stays out: this once carried "(PAF)" and real listings do not.
     */
    public static function setTerm(CatalogItem $item): ?string
    {
        return self::splitSet($item)[0];
    }

    /**
     * The card's own name as a seller would write it.
     *
     * We bracket a set onto a card name to tell two printings apart in our own
     * catalog — "Umbreon ex (30th Celebration)" — and that bracket is ours, not
     * eBay's. Since the set is already a keyword in its own right, keeping it
     * inside the name too only demands the seller wrote it twice. Brackets that
     * say something the set does not, like "(Pokemon Center Exclusive)", stay:
     * those are the printing, and sellers do write them.
     */
    public static function cardTerm(CatalogItem $item): string
    {
        $name = trim((string) $item->name);
        $said = array_flip(self::words(self::setName($item) ?? ''));

        if ($said === []) {
            return $name;
        }

        $cleaned = preg_replace_callback(
            '/\s*[\(\[]([^\)\]]*)[\)\]]/u',
            function (array $m) use ($said) {
                $words = self::words($m[1]);
                $known = array_filter($words, fn (string $w) => isset($said[$w]));

                return $words !== [] && count($known) === count($words) ? '' : $m[0];
            },
            $name,
        );

        return trim($cleaned ?? $name) ?: $name;
    }

    /**
     * The set name split into the part that names an expansion and the part that
     * names a run within it — "30th Celebration Promos" into "30th Celebration"
     * and "Promo".
     *
     * Both halves are search words, but they are different kinds of word: one
     * says which release, the other says which printing, and the printing words
     * belong with the card's other qualifiers. Splitting also lets the plural we
     * shelve under become the singular sellers write.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function splitSet(CatalogItem $item): array
    {
        $name = self::setName($item);

        if ($name === null) {
            return [null, null];
        }

        [$parent, $suffix] = Subsets::split($name);

        if ($parent !== null && ! self::identifiesNothing($parent)) {
            $name = $parent;
        } else {
            $suffix = null;
        }

        // What does the set name actually add, given what the card is called?
        // If all it adds is a word that identifies nothing on its own, it has
        // nothing to say here — judged the same way the whole name is judged.
        if (self::identifiesNothing(self::wordsBeyond($name, self::cardTerm($item)))) {
            return [null, $suffix === null ? null : self::suffixTerm($suffix)];
        }

        return [$name, $suffix === null ? null : self::suffixTerm($suffix)];
    }

    /**
     * The set name worth searching on at all, before it is split — null when it
     * names nothing or is too long to AND.
     */
    private static function setName(CatalogItem $item): ?string
    {
        $set = $item->set;

        if (! $set) {
            return null;
        }

        // A set's display name is not always the thing people search for. The
        // First Partner sets are named "Series 1/2/3" — which identifies nothing
        // on its own — while the line they belong to, "First Partners", appears
        // in 76% of their sold titles. So a generic name falls through to the
        // series it sits in rather than dropping the set from the search.
        $name = trim((string) $set->name);

        if (self::identifiesNothing($name)) {
            $name = trim((string) $set->series);
        }

        if (self::identifiesNothing($name)) {
            return null;
        }

        // eBay ANDs the keywords, so a long set name is a long list of words
        // every listing must carry — "Starter Deck 3: The Seven Warlords of the
        // Sea" costs more recall than the precision is worth.
        if (count(preg_split('/\s+/', $name)) > 4) {
            return null;
        }

        return $name;
    }

    /**
     * The suffix as a seller writes it. We shelve a promo run as "Promos"; 94.2%
     * of Mega Evolution Promo sold titles and 89.4% of SWSH Black Star Promos
     * ones carry the word, and they carry it singular.
     */
    private static function suffixTerm(string $suffix): string
    {
        return $suffix === 'Promos' ? 'Promo' : $suffix;
    }

    /**
     * $name with every word $said already contains removed, in order. Used to
     * ask what a set name adds to a card's own name rather than whether one
     * contains the other outright.
     */
    private static function wordsBeyond(string $name, ?string $said): string
    {
        // Compared as bare words, because a card name wears its set inside
        // brackets — "Umbreon ex (30th Celebration)" — and "(30th" is the same
        // word as "30th" to everyone except a string comparison.
        $already = array_flip(self::words((string) $said));

        $kept = array_filter(
            self::words($name),
            fn (string $word) => ! isset($already[$word]),
        );

        return trim(implode(' ', $kept));
    }

    /**
     * Lowercased words, punctuation dropped.
     *
     * @return array<int, string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return $m[0];
    }

    /**
     * A set or series name too generic to narrow anything. Every product line
     * has a "Promo" set, and "Series 2" means nothing without the line it
     * belongs to.
     */
    private static function identifiesNothing(string $name): bool
    {
        return trim($name) === ''
            || preg_match('/^(promos?|base|other|series\s+\d+)$/i', trim($name)) === 1;
    }

    /** @return array<int, string> */
    public static function qualifiers(CatalogItem $item): array
    {
        $attributes = $item->getAttribute('attributes') ?? [];
        $out = [];

        // "Promo", "Trainer Gallery" — the half of the set name that says which
        // printing rather than which release. It qualifies the card, so it sits
        // with the card's other qualifiers.
        if ($suffix = self::splitSet($item)[1]) {
            $out[] = $suffix;
        }

        // The chase tier, where sellers reliably write it.
        if ($rarity = self::rarityTerm($item)) {
            $out[] = $rarity;
        }

        $edition = $attributes['edition'] ?? null;
        if ($edition === 'first_edition') {
            $out[] = '1st Edition';
        } elseif ($edition === 'shadowless') {
            $out[] = 'Shadowless';
        }

        if (($attributes['variant'] ?? null) === 'reverse_holo') {
            $out[] = 'Reverse Holo';
        }

        // Lorcana foil is a distinct printing — pin its search to "Foil" so we
        // fetch the foil's (pricier) sales, not the base card's.
        if (($attributes['variant'] ?? null) === 'foil') {
            $out[] = 'Foil';
        }

        if (! empty($attributes['finish'])) {
            $out[] = self::finishTerm((string) $attributes['finish']);
        }

        // Pin a stamped promo's search to its stamp (GameStop / EB Games / …) so we
        // fetch that printing's sales, not the base card's.
        if (! empty($attributes['stamp'])) {
            $out[] = (new StampMatcher)->label((string) $attributes['stamp']);
        }

        // A promo filed in a set named "… Promos" would otherwise say "Promo"
        // twice — once for the set's printing half, once for the rarity.
        return array_values(array_unique($out));
    }

    /**
     * The card's rarity, where a seller writes it often enough to be worth
     * ANDing — because eBay ANDs, and a word 40% of real listings omit throws
     * away 40% of the comps.
     *
     * Two things here came out of measuring 1,200 sold titles per rarity rather
     * than from what the tiers are called:
     *
     * - The abbreviations are the minority spelling. "SIR" appears in 22.6% of
     *   Special Illustration Rare titles against 60.4% for the words in full,
     *   and "IR" in 9.8% against 67.8%. Searching the short form would discard
     *   three sales in four.
     * - We shelve one tier back to front. Our vocabulary says "Rare Secret";
     *   sellers write "Secret Rare" — 47.8% against 0.2% — so the term is not
     *   the stored string.
     *
     * Only the chase tiers are listed. A plain tier is not how anyone describes
     * a card they are selling: "Double Rare" appears in 29.8% of its own
     * listings, and ANDing it would cost seven comps in ten to say something the
     * collector number already says.
     */
    private static function rarityTerm(CatalogItem $item): ?string
    {
        $rarity = $item->getAttribute('attributes')['rarity'] ?? null;

        if (! is_string($rarity)) {
            return null;
        }

        return self::RARITY_TERMS[mb_strtolower(trim($rarity))] ?? null;
    }

    /**
     * The same wording the card page shows. Built there rather than here so a
     * printing cannot be advertised under one name and searched under another.
     */
    private static function finishTerm(string $finish): string
    {
        return CardDisplayName::finishLabel($finish);
    }
}
