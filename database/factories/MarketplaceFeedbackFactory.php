<?php

namespace Database\Factories;

use App\Models\MarketplaceDeal;
use App\Models\MarketplaceFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceFeedback> */
class MarketplaceFeedbackFactory extends Factory
{
    protected $model = MarketplaceFeedback::class;

    public function definition(): array
    {
        return [
            'marketplace_deal_id' => MarketplaceDeal::factory(),
            'rater_id' => User::factory(),
            'ratee_id' => User::factory(),
            'rating' => 5,
            'verified' => false,
        ];
    }
}
