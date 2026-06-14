<?php

namespace App\Support\Catalog;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pokémon sets often bundle a named "subset" of cards — Trainer Gallery,
 * Galarian Gallery, Shiny Vault, etc. — distinguished by a letter prefix on the
 * card number (TG01, GG12, SV001). The main set is plain-numeric (25, 191).
 *
 * We derive subsets from the number rather than storing them, and keep both the
 * derivation and the (portable, LIKE-only) query filter here so tiles and search
 * always agree.
 */
class Subsets
{
    /** Friendly names for known number prefixes. */
    private const NAMES = [
        'TG' => 'Trainer Gallery',
        'GG' => 'Galarian Gallery',
        'SV' => 'Shiny Vault',
        'RC' => 'Radiant Collection',
        'GP' => 'Galaxy Pack',
        'H' => 'Holo',
    ];

    /** The subset key for a card number: its leading letters, or 'main' if numeric. */
    public static function keyFor(?string $number): string
    {
        if ($number !== null && preg_match('/^[A-Za-z]+/', $number, $m)) {
            return strtoupper($m[0]);
        }

        return 'main';
    }

    /** Human label for a subset key. */
    public static function label(string $key): string
    {
        return $key === 'main' ? 'Main set' : (self::NAMES[$key] ?? $key);
    }

    /**
     * Constrain a catalog query to one subset — portable (no REGEXP): the main
     * set is "number starts with a digit"; a named subset is "number LIKE PREFIX%".
     *
     * @param  Builder<\App\Models\CatalogItem>  $query
     */
    public static function applyFilter(Builder $query, string $key): void
    {
        if ($key === 'main') {
            $query->where(function (Builder $q): void {
                for ($d = 0; $d <= 9; $d++) {
                    $q->orWhere('number', 'like', "{$d}%");
                }
            });

            return;
        }

        $query->where('number', 'like', strtoupper($key).'%');
    }
}
