<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;
use App\Support\Catalog\StampMatcher;

/**
 * The edition/variant/error terms that pin an eBay search to one specific
 * printing — so a 1st Edition card's comps aren't polluted with Unlimited sales
 * (and vice-versa). Shared by the sold-comp lookup and the "Shop on eBay" links.
 * Unlimited adds no positive term (sellers rarely write it); the comp classifier
 * instead rejects 1st-Edition/Shadowless listings for an Unlimited card.
 */
final class CardSearchTerms
{
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

        // What does the set name actually add, given what the card is called?
        //
        // Our shelving vocabulary is not eBay's. The 30th Celebration promos are
        // named "Umbreon ex (30th Celebration)" and filed in "30th Celebration
        // Promos", so the set contributed one word nobody writes in a title —
        // "Promos" — and eBay ANDs it, which returned nothing at all. Judge the
        // remainder the same way the whole name is judged: if all it adds is a
        // word that identifies nothing, the set has nothing to say here.
        if (self::identifiesNothing(self::wordsBeyond($name, $item->name))) {
            return null;
        }

        return $name;
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

        return $out;
    }

    private static function finishTerm(string $finish): string
    {
        if (preg_match('/^(\d{4})_(\d{4})$/', $finish, $m)) {
            return "{$m[1]}-{$m[2]}";
        }

        return ucwords(str_replace('_', ' ', $finish));
    }
}
