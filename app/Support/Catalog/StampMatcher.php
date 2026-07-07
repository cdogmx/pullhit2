<?php

namespace App\Support\Catalog;

use App\Models\CatalogItem;

/**
 * Retailer / prerelease STAMP promos (GameStop, EB Games, prerelease, staff, …)
 * are distinct printings that trade on their own — Pokémon issues these often.
 * This routes sold comps to the right stamped variant: a listing's stamp must
 * match the item's, a base (unstamped) card rejects any stamped listing, and one
 * stamped variant never absorbs another's sales. Known stamps have tuned title
 * patterns; any custom stamp an admin types is matched by its own words.
 */
class StampMatcher
{
    /** Well-behaved known stamps → a title-detection regex (word-bounded). */
    private const KNOWN = [
        'gamestop' => '/\bgame\s?stop\b/',
        'eb_games' => '/\beb\s?games\b/',
        'prerelease' => '/\bpre[-\s]?release\b/',
        'staff' => '/\bstaff\b/',
        'pokemon_center' => '/\bpok[eé]mon\s+center\b/',
        'walmart' => '/\bwalmart\b/',
    ];

    /** A generic "stamped / stamp promo" signal with no named retailer. */
    private const GENERIC = '/\bstamp(ed)?\b/';

    /** Display labels for known stamps; custom values are title-cased. */
    private const LABELS = [
        'gamestop' => 'GameStop',
        'eb_games' => 'EB Games',
        'prerelease' => 'Prerelease',
        'staff' => 'Staff',
        'pokemon_center' => 'Pokémon Center',
        'walmart' => 'Walmart',
    ];

    /** Suggestions for the admin combobox (free text is still allowed). */
    public const SUGGESTIONS = ['gamestop', 'eb_games', 'prerelease', 'staff', 'pokemon_center', 'walmart'];

    /** The item's stamp: its facet if set, else detected from its name, else null. */
    public function itemStamp(CatalogItem $item): ?string
    {
        $stamp = $item->getAttribute('attributes')['stamp'] ?? null;

        if ($stamp !== null && $stamp !== '') {
            return $this->canonical((string) $stamp);
        }

        // Legacy items that baked the stamp into the name ("Ho-Oh [Gamestop]").
        return $this->detect(mb_strtolower($item->name));
    }

    /**
     * Whether a listing title belongs to a card with the given stamp (null = the
     * base, unstamped card). A base card rejects any stamped listing; a stamped
     * card requires its own stamp and rejects a different known stamp.
     */
    public function matches(?string $itemStamp, string $lowerTitle): bool
    {
        $named = $this->knownInTitle($lowerTitle);
        $anyStamp = $named !== [] || (bool) preg_match(self::GENERIC, $lowerTitle);

        if ($itemStamp === null) {
            return ! $anyStamp;
        }

        $namesItem = $this->stampInTitle($itemStamp, $lowerTitle);
        $namesOtherKnown = array_diff($named, [$itemStamp]) !== [];

        return $namesItem && ! $namesOtherKnown;
    }

    /** eBay search qualifier for a stamped card (its label), or null for base. */
    public function searchTerm(?string $stamp): ?string
    {
        return ($stamp === null || $stamp === '') ? null : $this->label($stamp);
    }

    /** Human label for a stamp value ("gamestop" → "GameStop"). */
    public function label(string $stamp): string
    {
        $stamp = $this->canonical($stamp);

        return self::LABELS[$stamp] ?? ucwords(str_replace('_', ' ', $stamp));
    }

    /** Normalise a typed stamp to a storage key ("EB Games" → "eb_games"). */
    public function canonical(string $stamp): string
    {
        return trim((string) preg_replace('/\s+/', '_', mb_strtolower(trim($stamp))), '_');
    }

    private function detect(string $text): ?string
    {
        foreach (self::KNOWN as $key => $regex) {
            if (preg_match($regex, $text)) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<int, string> known stamp keys present in the title */
    private function knownInTitle(string $text): array
    {
        $out = [];
        foreach (self::KNOWN as $key => $regex) {
            if (preg_match($regex, $text)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    private function stampInTitle(string $stamp, string $text): bool
    {
        $stamp = $this->canonical($stamp);

        if (isset(self::KNOWN[$stamp])) {
            return (bool) preg_match(self::KNOWN[$stamp], $text);
        }

        // Custom stamp: match its own words as a phrase (underscores → spaces).
        $phrase = preg_quote(str_replace('_', ' ', $stamp), '/');

        return (bool) preg_match('/\b'.$phrase.'\b/', $text);
    }
}
