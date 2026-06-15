<?php

namespace App\Support\Pricecharting;

/**
 * Per-game configuration for building a catalog from a PriceCharting price-guide
 * CSV: which product line/category, how the console name maps to a set, and how
 * the collector number is read out of the product name (it differs per game —
 * One Piece/Yu-Gi-Oh trail a "SET-NNN" code, Magic has no number at all).
 */
final readonly class GameImportSpec
{
    /**
     * @param  string|null  $numberRegex  a regex with named groups `name` + `number`
     *                                     applied to the de-bracketed product name;
     *                                     null = this game has no collector number.
     */
    public function __construct(
        public string $lineSlug,
        public string $lineName,
        public string $category,
        public string $consolePrefix,
        public ?string $numberRegex,
    ) {}

    public static function forGame(string $game): self
    {
        return match ($game) {
            'one-piece' => new self(
                lineSlug: 'one-piece',
                lineName: 'One Piece Card Game',
                category: 'one-piece-cards',
                consolePrefix: 'One Piece',
                // "Ain OP07-002", "Luffy ST01-001", "Shanks P-001"
                numberRegex: '/^(?<name>.+?)\s+(?<number>[A-Z0-9]{1,6}-[A-Z0-9]{2,5}[A-Za-z]?)$/',
            ),
            'magic' => new self(
                lineSlug: 'magic',
                lineName: 'Magic: The Gathering',
                category: 'magic-cards',
                consolePrefix: 'Magic',
                numberRegex: null, // Magic product names carry no collector number
            ),
            'yugioh' => new self(
                lineSlug: 'yugioh',
                lineName: 'Yu-Gi-Oh!',
                category: 'yugioh-cards',
                consolePrefix: 'YuGiOH',
                // "Blue-Eyes White Dragon LOB-001", "Dark Magician SDY-006"
                numberRegex: '/^(?<name>.+?)\s+(?<number>[A-Z0-9]{2,5}-[A-Z]{0,2}\d{2,4})$/',
            ),
            default => throw new \InvalidArgumentException("Unknown PriceCharting game [{$game}]."),
        };
    }
}
