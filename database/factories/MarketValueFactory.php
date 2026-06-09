<?php

namespace Database\Factories;

use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MarketValue>
 */
class MarketValueFactory extends Factory
{
    protected $model = MarketValue::class;

    public function definition(): array
    {
        $median = fake()->numberBetween(100, 500_00);

        return [
            'catalog_item_id' => CatalogItem::factory(),
            'state_key' => 'NM',
            'condition' => Condition::NearMint,
            'grading_company_id' => null,
            'grade' => null,
            'median' => $median,
            'p25' => (int) round($median * 0.9),
            'p75' => (int) round($median * 1.1),
            'low' => (int) round($median * 0.8),
            'high' => (int) round($median * 1.3),
            'n_sales' => fake()->numberBetween(3, 30),
            'confidence' => fake()->randomFloat(3, 0.2, 0.95),
            'half_life_days' => fake()->numberBetween(14, 90),
            'trend_30d' => fake()->randomFloat(2, -20, 20),
            'trend_90d' => null,
            'currency' => 'USD',
            'computed_at' => Carbon::now(),
        ];
    }
}
