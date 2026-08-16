<?php

namespace Database\Seeders;

use App\Models\Rarity;
use Illuminate\Database\Seeder;

/**
 * How each source's rarity string is presented in the filter, and where it sits
 * from common to chase.
 *
 * Japanese rarities keep their own entries rather than being folded into an
 * English equivalent — an Art Rare and an Illustration Rare are the same slot in
 * two markets, not the same card, and merging them would quietly claim they
 * trade alike. They sit adjacent in the order and say which market they are from.
 *
 * Re-runnable: matched on `value`, so re-seeding corrects labels and order
 * without touching anything else.
 */
class RaritySeeder extends Seeder
{
    /** [raw value, label, sort order, hidden]. Order runs common → chase. */
    private const RARITIES = [
        // Not a rarity so much as a channel, but people filter by it constantly.
        ['Promo', 'Promo', 5, false],
        ['L', 'Leader', 6, false],

        ['Common', 'Common', 10, false],
        ['C', 'Common', 11, false],
        ['Uncommon', 'Uncommon', 20, false],
        ['UC', 'Uncommon', 21, false],
        ['Ultra-Rare Uncommon', 'Uncommon', 22, true], // one row, clearly wrong
        ['Rare', 'Rare', 30, false],
        ['R', 'Rare', 31, false],

        ['Rare Holo', 'Holo Rare', 40, false],
        ['Holo Rare', 'Holo Rare (JP)', 41, false],
        ['Double Rare', 'Double Rare', 45, false],
        ['Triple Rare', 'Triple Rare (JP)', 47, false],

        // Mechanic-era holos. Same tier, distinguished by the mechanic.
        ['Rare Holo EX', 'EX', 50, false],
        ['Rare Holo GX', 'GX', 50, false],
        ['Rare Holo V', 'V', 50, false],
        ['Rare Holo VMAX', 'VMAX', 50, false],
        ['Rare Holo VSTAR', 'VSTAR', 50, false],
        ['Rare Holo LV.X', 'LV.X', 50, false],
        ['Rare Prime', 'Prime', 50, false],
        ['Rare BREAK', 'BREAK', 50, false],
        ['Rare Prism Star', 'Prism Star', 50, false],
        ['Prism Rare', 'Prism Rare (JP)', 51, false],
        ['Epic', 'Epic', 52, false],
        ['Rare Holo Star', 'Gold Star', 55, false],
        ['Rare Shining', 'Shining', 55, false],
        ['Shining', 'Shining (JP)', 56, false],
        ['LEGEND', 'LEGEND', 55, false],
        ['Amazing Rare', 'Amazing Rare', 55, false],
        ['Radiant Rare', 'Radiant Rare', 55, false],
        ['Kagayaku', 'Kagayaku / Shiny (JP)', 56, false],
        ['Classic Collection', 'Classic Collection', 57, false],

        ['Ultra Rare', 'Ultra Rare', 60, false],
        ['Rare Ultra', 'Ultra Rare', 60, false],
        ['Super Rare', 'Super Rare', 60, false],
        ['SR', 'Super Rare', 61, false],
        ['Super Rare Holo', 'Super Rare Holo (JP)', 61, false],
        ['Mega Ultra Rare', 'Mega Ultra Rare (JP)', 62, false],
        ['Trainer Gallery Rare Holo', 'Trainer Gallery', 63, false],
        ['Trainer Rare', 'Trainer Rare (JP)', 64, false],
        ['Character Rare', 'Character Rare (JP)', 65, false],
        ['Character Super Rare', 'Character Super Rare (JP)', 66, false],
        ['ACE SPEC Rare', 'ACE SPEC Rare', 68, false],
        ['Rare ACE', 'ACE SPEC Rare', 68, false],
        ['ACE Rare', 'ACE Rare (JP)', 68, false],

        ['Illustration Rare', 'Illustration Rare', 70, false],
        ['Art Rare', 'Art Rare (JP)', 70, false],
        ['Legendary', 'Legendary', 70, false],
        ['Shiny Rare', 'Shiny Rare', 72, false],
        ['Rare Shiny', 'Shiny Rare', 72, false],
        ['Rare Shiny GX', 'Shiny GX', 73, false],
        ['Mega Attack Rare', 'Mega Attack Rare', 75, false],

        ['Special Illustration Rare', 'Special Illustration Rare', 80, false],
        ['Special Art Rare', 'Special Art Rare (JP)', 80, false],
        ['Shiny Ultra Rare', 'Shiny Ultra Rare', 82, false],
        ['Shiny Secret Rare', 'Shiny Secret Rare (JP)', 85, false],

        ['Rare Secret', 'Secret Rare', 90, false],
        ['SEC', 'Secret Rare', 90, false],
        ['Rare Rainbow', 'Rainbow Rare', 90, false],
        ['Hyper Rare', 'Hyper Rare', 90, false],
        ['Enchanted', 'Enchanted', 92, false],
        ['Mega Hyper Rare', 'Mega Hyper Rare', 95, false],
        ['Iconic', 'Iconic', 95, false],
        ['Black White Rare', 'Black White Rare', 96, false],

        // Placeholders a source emitted instead of a rarity.
        ['None', 'Unspecified', 999, true],
        ['Unknown', 'Unspecified', 999, true],
    ];

    public function run(): void
    {
        foreach (self::RARITIES as [$value, $label, $order, $hidden]) {
            Rarity::updateOrCreate(
                ['value' => $value],
                ['label' => $label, 'sort_order' => $order, 'is_hidden' => $hidden],
            );
        }
    }
}
