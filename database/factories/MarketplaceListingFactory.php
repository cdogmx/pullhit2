<?php

namespace Database\Factories;

use App\Enums\Condition;
use App\Enums\ListingCategory;
use App\Enums\ListingStatus;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceListing> */
class MarketplaceListingFactory extends Factory
{
    protected $model = MarketplaceListing::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => ListingCategory::RawSingle,
            'title' => $this->faker->words(4, true),
            'description' => $this->faker->sentence(),
            'price_cents' => $this->faker->numberBetween(500, 500000),
            'currency' => 'USD',
            'condition' => Condition::NearMint,
            'accepts_offers' => true,
            'accepts_direct' => true,
            'accepts_escrow' => true,
            'status' => ListingStatus::Active,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::Draft, 'expires_at' => null]);
    }

    public function slab(?string $cert = null): static
    {
        return $this->state(fn () => [
            'category' => ListingCategory::GradedSlab,
            'condition' => null,
            'grade' => '10',
            'cert_number' => $cert ?? (string) $this->faker->numberBetween(10000000, 99999999),
        ]);
    }
}
