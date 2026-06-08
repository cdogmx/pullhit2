<?php

namespace Database\Factories;

use App\Models\ProductLine;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductLine>
 */
class ProductLineFactory extends Factory
{
    protected $model = ProductLine::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'vertical_id' => Vertical::factory(),
            'slug' => str($name)->slug()->toString(),
            'name' => ucwords($name),
            'metadata' => null,
        ];
    }
}
