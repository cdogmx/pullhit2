<?php

namespace App\Support\Catalog;

/**
 * Maps a lorcana-api.com card into a single MappedItem. Lorcana cards have no
 * holo/edition axis (every card is one printing; alt-arts like Enchanted/Iconic
 * carry their own collector number and Unique_ID, so they arrive as distinct
 * cards), hence one item per card. The feed carries no prices — anchorCents is
 * always 0 and valuations come from eBay sold comps (like every product line).
 */
class LorcanaCardMapper
{
    /**
     * @param  array<string, mixed>  $card
     * @return array<int, MappedItem>
     */
    public function map(array $card): array
    {
        $externalIds = array_filter([
            'lorcana_id' => $card['Unique_ID'] ?? null,
            'lorcana_image' => $card['Image'] ?? null,
        ]);

        $attributes = array_filter([
            'language' => 'en',                       // lorcana-api is English-only
            'rarity' => $card['Rarity'] ?? 'Unknown', // incl. Enchanted/Iconic/Epic
            'variant' => 'normal',                    // no variant axis in Lorcana
            'illustrator' => $card['Artist'] ?? null,
            'type' => $card['Type'] ?? null,          // Character/Action/Item/Location
            'ink_color' => $card['Color'] ?? null,    // e.g. "Amber, Steel" (dual-ink)
            'franchise' => $card['Franchise'] ?? null,
            'classifications' => $card['Classifications'] ?? null,
            'cost' => $this->int($card['Cost'] ?? null),
            'lore' => $this->int($card['Lore'] ?? null),
            'strength' => $this->int($card['Strength'] ?? null),
            'willpower' => $this->int($card['Willpower'] ?? null),
            'move_cost' => $this->int($card['Move_Cost'] ?? null),
            'abilities' => $card['Abilities'] ?? null,
            'body_text' => $card['Body_Text'] ?? null,
            'flavor_text' => $card['Flavor_Text'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Inkable is a real boolean (false is meaningful) — set it outside the
        // array_filter so a false value isn't treated like "missing".
        if (array_key_exists('Inkable', $card)) {
            $attributes['inkable'] = (bool) $card['Inkable'];
        }

        return [new MappedItem(
            name: $card['Name'] ?? 'Unknown',
            number: isset($card['Card_Num']) ? (string) $card['Card_Num'] : null,
            attributes: $attributes,
            externalIds: $externalIds,
            anchorCents: 0,
        )];
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
