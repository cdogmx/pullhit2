<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItem>
 *
 * Produces a valid TCG single by default. Note: `language` is a generated column
 * and must NOT be set here — it derives from the `attributes` JSON. `identity_hash`
 * is set directly (factories run unguarded); real catalog rows go through
 * App\Actions\Catalog\CreateCatalogItem which computes a deterministic hash.
 */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        $name = fake()->unique()->firstName().' '.fake()->randomElement(['VMAX', 'ex', 'GX', 'V']);

        return [
            'vertical_id' => Vertical::factory(),
            'product_line_id' => ProductLine::factory(),
            'set_id' => Set::factory(),
            'item_type' => ItemType::Single,
            'name' => $name,
            'number' => (string) fake()->numberBetween(1, 200),
            'attributes' => [
                'language' => 'en',
                'rarity' => fake()->randomElement(['Common', 'Rare', 'Illustration Rare']),
                'variant' => fake()->randomElement(['normal', 'holo', 'reverse_holo']),
            ],
            'external_ids' => null,
            'primary_image_path' => null,
            'identity_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }

    public function sealed(): static
    {
        return $this->state(fn (): array => [
            'item_type' => ItemType::Sealed,
            'number' => null,
            'attributes' => [
                'sealed_type' => 'booster_box',
                'language' => 'en',
                'pack_count' => 36,
            ],
        ]);
    }
}
