<?php

namespace Database\Factories;

use App\Models\GradingCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingCompany>
 */
class GradingCompanyFactory extends Factory
{
    protected $model = GradingCompany::class;

    public function definition(): array
    {
        $name = fake()->unique()->lexify('???');

        return [
            'slug' => strtolower($name),
            'name' => strtoupper($name),
            'scale_max' => 10,
            'supports_half_grades' => fake()->boolean(),
            'supports_subgrades' => fake()->boolean(),
            'supports_pristine_black_label' => false,
        ];
    }
}
