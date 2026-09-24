<?php

namespace Database\Factories;

use App\Models\GradePrediction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradePrediction>
 */
class GradePredictionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Milotic ex 237/191',
            'sides' => ['front' => ['usable' => true, 'surface' => ['score' => 900]]],
            'estimate' => [
                'score' => 874,
                'sigma' => 85.0,
                'probs' => ['10' => 0.31, '9' => 0.42, '8' => 0.18, 'other' => 0.09],
                'unseen' => ['corners', 'edges'],
            ],
            'observed' => ['centering', 'surface'],
            'guides_source' => 'manual',
        ];
    }
}
