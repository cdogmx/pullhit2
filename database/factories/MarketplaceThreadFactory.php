<?php

namespace Database\Factories;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceThread> */
class MarketplaceThreadFactory extends Factory
{
    protected $model = MarketplaceThread::class;

    public function definition(): array
    {
        return [
            'marketplace_listing_id' => MarketplaceListing::factory(),
            'buyer_id' => User::factory(),
            'seller_id' => User::factory(),
        ];
    }
}
