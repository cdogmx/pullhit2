<?php

namespace App\Support\Catalog;

/**
 * Normalises a card name as stored in the catalog.
 *
 * Lives here because the importer and the rehash command must agree exactly: the
 * name feeds identity_hash, so two copies of this logic that drift produce two
 * rows for one card.
 */
final class CardName
{
    /**
     * Strip the " - 003/084" collector number TCGCSV appends to some product
     * names, so they match the clean names the per-game APIs publish.
     *
     * Matched on the slash, which is what makes it a collector number rather than
     * a title: every Lorcana character is "Name - Subtitle" and must survive.
     * The number is not always last — sets whose printings are distinguished by a
     * parenthetical put it in the middle, "Team Rocket's Dugtrio - 101/217 (Team
     * Rocket)" — and promos put a hyphenated set code after the slash,
     * "Pikachu - 001/XY-P".
     */
    public static function clean(string $name): string
    {
        $stripped = preg_replace(
            '/\s*-\s*[0-9A-Za-z]+\/[0-9A-Za-z]+(?:-[0-9A-Za-z]+)*(?=\s*\(|\s*$)/',
            '',
            $name,
        );

        return trim((string) $stripped) ?: $name;
    }
}
