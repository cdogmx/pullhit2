<?php

namespace Database\Factories;

use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vertical>
 */
class VerticalFactory extends Factory
{
    protected $model = Vertical::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => str($name)->slug()->toString(),
            'name' => ucwords($name),
            'config' => null,
        ];
    }
}
