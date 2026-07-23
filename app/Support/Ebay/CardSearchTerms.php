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
