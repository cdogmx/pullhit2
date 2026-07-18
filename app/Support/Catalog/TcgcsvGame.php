<?php

namespace App\Support\Catalog;

use InvalidArgumentException;

/**
 * A game we can import from TCGCSV by TCGplayer group id — the TCGplayer
 * category to read plus the product line the cards land in. TCGCSV is our
 * "day one" source: it has a set the moment TCGplayer lists it, weeks before
 * the per-game APIs (pokemontcg.io, lorcana-api.com) publish it. Once those
 * catch up their importer refines the very same rows.
 */
enum TcgcsvGame: string
{
    case Pokemon = 'pokemon';
    case Lorcana = 'lorcana';

    /** TCGplayer category id this game's groups live under. */
    public function categoryId(): int
    {
        return match ($this) {
            self::Pokemon => TcgcsvClient::POKEMON,
            self::Lorcana => TcgcsvClient::LORCANA,
        };
    }

    /** Display name for the product line the cards are filed under. */
    public function productLineName(): string
    {
        return match ($this) {
            self::Pokemon => 'Pokémon',
            self::Lorcana => 'Disney Lorcana',
        };
    }

    /**
     * Prefix for the image-store bucket path, matching what the game's own
     * importer uses so a later refresh overwrites rather than orphans.
     */
    public function imageLine(): string
    {
        return $this->value;
    }

    /**
     * Whether a card's finishes are separate catalog rows. Pokémon prices holo /
     * reverse-holo as distinct printings, so each becomes its own item. Lorcana
     * has no finish axis in our catalog — every card is one row (`variant:
     * normal`), and alt-arts like Enchanted carry their own collector number, so
     * TCGplayer's Cold Foil/Holofoil rows must collapse into that single item
     * rather than double every card in the set.
     */
    public function hasFinishVariants(): bool
    {
        return $this === self::Pokemon;
    }

    public static function fromSlug(string $slug): self
    {
        return self::tryFrom(strtolower(trim($slug)))
            ?? throw new InvalidArgumentException(
                "Unknown TCGCSV game [{$slug}]. Supported: ".implode(', ', array_column(self::cases(), 'value'))
            );
    }
}
