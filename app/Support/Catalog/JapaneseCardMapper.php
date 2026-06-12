<?php

namespace App\Support\Catalog;

use Illuminate\Support\Arr;

/**
 * Maps a TCGCSV (TCGplayer Japan) product + its price rows into one MappedItem
 * per finish present — mirroring PokemonCardMapper, but for Japanese cards. The
 * collector number and rarity come from `extendedData`; the finish's market
 * price is the valuation anchor. Language is fixed to `ja`.
 */
class JapaneseCardMapper
{
    /** TCGplayer subtype names → our `variant` enum. */
    private const SUBTYPE_VARIANT = [
        'Normal' => 'normal',
        'Holofoil' => 'holo',
        'Reverse Holofoil' => 'reverse_holo',
    ];

    /**
     * @param  array<string, mixed>  $product
     * @param  array<int, array<string, mixed>>  $prices  this product's price rows
     * @return array<int, MappedItem>
     */
    public function map(array $product, array $prices): array
    {
        $extended = collect($product['extendedData'] ?? [])->keyBy('name');
        $number = $this->collectorNumber(Arr::get($extended->get('Number', []), 'value'));
        $rarity = Arr::get($extended->get('Rarity', []), 'value') ?: 'Unknown';
        $name = $product['name'] ?? ($product['cleanName'] ?? 'Unknown');

        $externalIds = array_filter([
            'tcgplayer_product_id' => $product['productId'] ?? null,
            'tcgplayer_image' => $product['imageUrl'] ?? null,
        ]);

        if ($prices === []) {
            return [$this->item($name, $number, $rarity, 'normal', $externalIds, 0)];
        }

        // One item per distinct variant; same-variant prices dedup (last wins).
        $items = [];
        foreach ($prices as $price) {
            $variant = self::SUBTYPE_VARIANT[$price['subTypeName'] ?? ''] ?? 'normal';
            $anchor = $this->cents($price['marketPrice'] ?? $price['midPrice'] ?? $price['lowPrice'] ?? null);
            $items[$variant] = $this->item($name, $number, $rarity, $variant, $externalIds, $anchor);
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>  $externalIds
     */
    private function item(string $name, ?string $number, string $rarity, string $variant, array $externalIds, int $anchorCents): MappedItem
    {
        return new MappedItem(
            name: $name,
            number: $number,
            attributes: array_filter([
                'language' => 'ja',
                'rarity' => $rarity,
                'variant' => $variant,
            ], fn ($v) => $v !== null && $v !== ''),
            externalIds: $externalIds,
            anchorCents: $anchorCents,
        );
    }

    /** "001/080" → "001" (the collector number, matching how we store EN numbers). */
    private function collectorNumber(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        return trim(explode('/', $raw)[0]) ?: null;
    }

    private function cents(?float $value): int
    {
        return $value ? (int) round($value * 100) : 0;
    }
}
