<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Enums\DealType;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceDeal> */
class MarketplaceDealFactory extends Factory
{
    protected $model = MarketplaceDeal::class;

    public function definition(): array
    {
        return [
            'marketplace_listing_id' => MarketplaceListing::factory(),
            'buyer_id' => User::factory(),
            'seller_id' => User::factory(),
            'proposed_by_id' => User::factory(),
            'type' => DealType::Direct,
            'status' => DealStatus::Proposed,
            'agreed_price_cents' => 50000,
            'currency' => 'USD',
        ];
    }

    public function escrow(): static
    {
        return $this->state(fn () => ['type' => DealType::Escrow]);
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'status' => DealStatus::Complete,
            'buyer_confirmed_at' => now(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
