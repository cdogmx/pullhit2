<?php

namespace App\Support\Pricecharting;

use Illuminate\Support\Str;

/**
 * Classifies the bracket tag from a PriceCharting product name into our facets.
 * A tag may be compound — "1st Edition Red Cheeks" → edition=first_edition +
 * finish=red_cheeks — so we peel known edition/variant words first and treat the
 * remainder as an error/promo `finish`.
 */
final class TagClassifier
{
    /**
     * @return array{edition: ?string, variant: ?string, finish: ?string}
     */
    public static function classify(?string $tag): array
    {
        $out = ['edition' => null, 'variant' => null, 'finish' => null];

        if ($tag === null || trim($tag) === '') {
            return $out;
        }

        $t = ' '.strtolower(trim($tag)).' ';

        if (str_contains($t, '1st edition') || str_contains($t, 'first edition')) {
            $out['edition'] = 'first_edition';
            $t = str_replace(['1st edition', 'first edition'], ' ', $t);
        } elseif (str_contains($t, 'shadowless')) {
            $out['edition'] = 'shadowless';
            $t = str_replace('shadowless', ' ', $t);
        }

        $hasReverse = str_contains($t, 'reverse');
        if ($hasReverse) {
            $out['variant'] = 'reverse_holo';
            $t = str_replace('reverse', ' ', $t);
        }

        $rest = trim((string) preg_replace('/\s+/', ' ', $t));

        // A standalone "[Holo]" is holo-ness, not an error tag. (A compound like
        // "Cosmos Holo" stays a finish.) "Reverse Holo" → the redundant "holo"
        // after peeling "reverse" is dropped.
        if ($rest === 'holo') {
            if (! $hasReverse) {
                $out['variant'] = 'holo';
            }
            $rest = '';
        }

        // Whatever remains is an error/promo descriptor (e.g. "red cheeks",
        // "black dot error", "1999-2000", "master ball", "cosmos holo").
        if ($rest !== '') {
            $out['finish'] = Str::slug($rest, '_');
        }

        return $out;
    }
}
