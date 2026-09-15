<?php

namespace Database\Factories;

use App\Models\MarketplaceMessage;
use App\Models\MarketplaceThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceMessage> */
class MarketplaceMessageFactory extends Factory
{
    protected $model = MarketplaceMessage::class;

    public function definition(): array
    {
        return [
            'marketplace_thread_id' => MarketplaceThread::factory(),
            'sender_id' => User::factory(),
            'body' => $this->faker->sentence(),
        ];
    }
}
