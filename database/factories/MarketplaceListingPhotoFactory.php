<?php

namespace Database\Factories;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceListingPhoto> */
class MarketplaceListingPhotoFactory extends Factory
{
    protected $model = MarketplaceListingPhoto::class;

    public function definition(): array
    {
        return [
            'marketplace_listing_id' => MarketplaceListing::factory(),
            'path' => 'https://example.test/photo-'.$this->faker->uuid().'.jpg',
            'sort_order' => 0,
        ];
    }
}
