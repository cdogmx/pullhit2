<?php

namespace App\Support\Catalog;

/**
 * pokemontcg.io ships some "subsets" as their own sets, named after the parent
 * expansion plus a gallery suffix — e.g. "Astral Radiance" (parent) and "Astral
 * Radiance Trainer Gallery" (child), or "Hidden Fates" and "Hidden Fates Shiny
 * Vault". We nest the children under their parent in browse rather than listing
 * them as sibling sets.
 */
class Subsets
{
    /** Set-name suffixes that mark a sub-set of a parent expansion. */
    public const SUFFIXES = ['Trainer Gallery', 'Galarian Gallery', 'Shiny Vault'];

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
