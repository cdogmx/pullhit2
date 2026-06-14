<?php

namespace App\Support\Catalog;

/**
 * Builds a human display name that distinguishes printings of the same card —
 * "Charizard (1st Edition)", "Charizard (Shadowless)", "Pikachu (Reverse Holo)",
 * "Charizard (Black Dot Error)". The bare card name alone can't tell three
 * printings apart now that editions/variants are modeled.
 */
final class CardDisplayName
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function for(string $name, array $attributes): string
    {
        $bits = [];

        $edition = $attributes['edition'] ?? null;
        $finish = $attributes['finish'] ?? null;

        if ($edition === 'first_edition') {
            $bits[] = '1st Edition';
        } elseif ($edition === 'shadowless') {
            $bits[] = 'Shadowless';
        } elseif ($edition === 'unlimited' && empty($finish)) {
            // Only worth saying "Unlimited" to disambiguate the base from its
            // 1st Edition / Shadowless siblings — not on top of an error tag.
            $bits[] = 'Unlimited';
        }

        if (($attributes['variant'] ?? null) === 'reverse_holo') {
            $bits[] = 'Reverse Holo';
        }

        if (! empty($finish)) {
            $bits[] = self::finishLabel((string) $finish);
        }

        return $bits === [] ? $name : $name.' ('.implode(', ', $bits).')';
    }

    private static function finishLabel(string $finish): string
    {
        // Year ranges: 1999_2000 → 1999-2000.
        if (preg_match('/^(\d{4})_(\d{4})$/', $finish, $m)) {
            return "{$m[1]}-{$m[2]}";
        }

        return ucwords(str_replace('_', ' ', $finish));
    }
}
