<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;

/**
 * The edition/variant/error terms that pin an eBay search to one specific
 * printing — so a 1st Edition card's comps aren't polluted with Unlimited sales
 * (and vice-versa). Shared by the sold-comp lookup and the "Shop on eBay" links.
 * Unlimited adds no positive term (sellers rarely write it); the comp classifier
 * instead rejects 1st-Edition/Shadowless listings for an Unlimited card.
 */
final class CardSearchTerms
{
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

        if (! empty($attributes['finish'])) {
            $out[] = self::finishTerm((string) $attributes['finish']);
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
