<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * Hand-seeds the Pokémon catalog (§11 Phase 1 "done when"): the tcg vertical,
 * a Pokémon product line, an EN set, and a couple of items created through the
 * validated CreateCatalogItem action. Idempotent end to end.
 */
class PokemonCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $vertical = Vertical::updateOrCreate(
            ['slug' => 'tcg'],
            ['name' => 'Trading Card Games'],
        );

        $pokemon = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
            ['name' => 'Pokémon'],
        );

        $set = Set::updateOrCreate(
            ['product_line_id' => $pokemon->id, 'slug' => '151-en'],
            [
                'name' => '151',
                'code' => 'MEW',
                'language' => 'en',
                'series' => 'Scarlet & Violet',
                'set_family' => '151',
                'released_at' => '2023-09-22',
                'external_ids' => ['ptcgio_set_id' => 'sv3pt5'],
            ],
        );

        $create = app(CreateCatalogItem::class);

        $create(
            vertical: $vertical,
            productLine: $pokemon,
            set: $set,
            itemType: ItemType::Single,
            name: 'Pikachu',
            number: '173/165',
            attributes: [
                'language' => 'en',
                'rarity' => 'Illustration Rare',
                'variant' => 'reverse_holo',
                'illustrator' => 'sowsow',
                'hp' => 60,
                'type' => 'Lightning',
            ],
            externalIds: ['ptcgio_id' => 'sv3pt5-173'],
        );

        $create(
            vertical: $vertical,
            productLine: $pokemon,
            set: $set,
            itemType: ItemType::Sealed,
            name: '151 Booster Box',
            attributes: [
                'sealed_type' => 'booster_box',
                'language' => 'en',
                'pack_count' => 36,
            ],
        );
    }
}
