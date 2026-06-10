<?php

namespace App\Support\Catalog;

/**
 * Maps a pokemontcg.io card into one MappedItem per finish present in its
 * TCGplayer prices (the catalog is variant-level). The vision-extracted facets
 * become validated TcgVertical attributes; the finish's market price is the
 * valuation anchor.
 */
class PokemonCardMapper
{
    /** pokemontcg.io finish keys → our `variant` enum. */
    private const FINISH_VARIANT = [
        'normal' => 'normal',
        'holofoil' => 'holo',
        'reverseHolofoil' => 'reverse_holo',
        '1stEditionHolofoil' => '1st_edition',
        '1stEditionNormal' => '1st_edition',
        'unlimitedHolofoil' => 'holo',
    ];

    /**
     * @param  array<string, mixed>  $card
     * @return array<int, MappedItem>
     */
    public function map(array $card): array
    {
        $externalIds = array_filter([
            'ptcgio_id' => $card['id'] ?? null,
            'ptcgio_image' => data_get($card, 'images.large') ?? data_get($card, 'images.small'),
        ]);

        $facets = [
            'rarity' => $card['rarity'] ?? 'Unknown',
            'illustrator' => $card['artist'] ?? null,
            // hp is a numeric string on pokemontcg.io ("60"); the registry types it as integer.
            'hp' => isset($card['hp']) && is_numeric($card['hp']) ? (int) $card['hp'] : null,
            'type' => data_get($card, 'types.0'),
        ];

        $prices = (array) data_get($card, 'tcgplayer.prices', []);

        if ($prices === []) {
            return [$this->item($card, $facets, $externalIds, 'normal', 0)];
        }

        // One item per distinct variant (a finish that maps to an existing
        // variant overwrites — dedup keeps the catalog clean).
        $items = [];
        foreach ($prices as $finish => $price) {
            $variant = self::FINISH_VARIANT[$finish] ?? 'normal';
            $anchor = $this->cents($price['market'] ?? $price['mid'] ?? $price['low'] ?? null);
            $items[$variant] = $this->item($card, $facets, $externalIds, $variant, $anchor);
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $externalIds
     */
    private function item(array $card, array $facets, array $externalIds, string $variant, int $anchorCents): MappedItem
    {
        $attributes = array_filter([
            'language' => 'en',
            'rarity' => $facets['rarity'],
            'variant' => $variant,
            'illustrator' => $facets['illustrator'],
            'hp' => $facets['hp'],
            'type' => $facets['type'],
        ], fn ($v) => $v !== null && $v !== '');

        return new MappedItem(
            name: $card['name'] ?? 'Unknown',
            number: isset($card['number']) ? (string) $card['number'] : null,
            attributes: $attributes,
            externalIds: $externalIds,
            anchorCents: $anchorCents,
        );
    }

    private function cents(?float $value): int
    {
        return $value ? (int) round($value * 100) : 0;
    }
}
