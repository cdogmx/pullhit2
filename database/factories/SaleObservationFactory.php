<?php

namespace Database\Factories;

use App\Enums\Condition;
use App\Enums\Venue;
use App\Models\CatalogItem;
use App\Models\SaleObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleObservation>
 */
class SaleObservationFactory extends Factory
{
    protected $model = SaleObservation::class;

    public function definition(): array
    {
        return [
            'catalog_item_id' => CatalogItem::factory(),
            'condition' => Condition::NearMint,
            'grading_company_id' => null,
            'grade' => null,
            'grade_label' => null,
            'venue' => fake()->randomElement(Venue::cases()),
            'price' => fake()->numberBetween(100, 500_00),
            'currency' => 'USD',
            'observed_at' => fake()->dateTimeBetween('-90 days'),
            'source_listing_id' => null,
            'is_outlier' => false,
            'raw' => null,
        ];
    }
}
