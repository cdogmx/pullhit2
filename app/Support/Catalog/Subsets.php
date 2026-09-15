<?php

namespace App\Support\Catalog;

/**
 * pokemontcg.io ships some "subsets" as their own sets, named after the parent
 * expansion plus a gallery suffix — e.g. "Astral Radiance" (parent) and "Astral
 * Radiance Trainer Gallery" (child), or "Hidden Fates" and "Hidden Fates Shiny
 * Vault". We nest the children under their parent in browse rather than listing
 * them as sibling sets.
 *
 * The same shape covers a promo run that belongs to an expansion but is printed
 * outside its numbering: "30th Celebration Promos" sits under "30th
 * Celebration" even though its cards are numbered in the Black Star sequence.
 * Without the nesting those cards are only findable by knowing to look in a
 * promo set of ninety-odd unrelated cards.
 */
class Subsets
{
    /** Set-name suffixes that mark a sub-set of a parent expansion. */
    public const SUFFIXES = [
        'Trainer Gallery',
        'Galarian Gallery',
        'Shiny Vault',
        // TCGplayer ships these two the same way pokemontcg.io ships the
        // galleries: a separate group named after its parent expansion. The
        // 30th Celebration prints both — a Classic Collection of reprints, and
        // a promo run numbered in the Black Star sequence rather than the set's.
        'Classic Collection',
        'Promos',
    ];

    /**
     * Split "Astral Radiance Trainer Gallery" into ["Astral Radiance", "Trainer
     * Gallery"]. Returns [null, null] when the name carries no known suffix.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function split(string $name): array
    {
        foreach (self::SUFFIXES as $suffix) {
            $needle = ' '.$suffix;
            if (str_ends_with($name, $needle)) {
                return [substr($name, 0, -strlen($needle)), $suffix];
            }
        }

        return [null, null];
    }
}
