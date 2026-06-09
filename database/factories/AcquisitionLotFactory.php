<?php

namespace Database\Factories;

use App\Models\AcquisitionLot;
use App\Models\CollectionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcquisitionLot>
 */
class AcquisitionLotFactory extends Factory
{
    protected $model = AcquisitionLot::class;

    public function definition(): array
    {
        return [
            'collection_item_id' => CollectionItem::factory(),
            'quantity' => 1,
            'unit_cost' => fake()->numberBetween(100, 200_00),
            'fees' => 0,
            'acquired_at' => fake()->dateTimeBetween('-2 years'),
            'source' => fake()->randomElement(['eBay', 'TCGplayer', 'LGS', 'Trade', null]),
        ];
    }
}
