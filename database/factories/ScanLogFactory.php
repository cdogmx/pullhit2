<?php

namespace Database\Factories;

use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanLog>
 */
class ScanLogFactory extends Factory
{
    protected $model = ScanLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mode' => 'single',
            'cards' => 1,
            'ai_reads' => 1,
            'cache_hits' => 0,
            'credits_spent' => 0,
        ];
    }
}
