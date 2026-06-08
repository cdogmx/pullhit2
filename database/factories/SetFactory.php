<?php

namespace Database\Factories;

use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Set>
 */
class SetFactory extends Factory
{
    protected $model = Set::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'product_line_id' => ProductLine::factory(),
            'slug' => str($name)->slug()->toString(),
            'name' => ucwords($name),
            'code' => strtoupper(fake()->unique()->bothify('???')),
            'language' => 'en',
            'series' => null,
            'set_family' => null,
            'released_at' => fake()->dateTimeBetween('-5 years'),
            'external_ids' => null,
        ];
    }
}
