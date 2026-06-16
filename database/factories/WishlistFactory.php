<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Wishlist>
 */
class WishlistFactory extends Factory
{
    protected $model = Wishlist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_public' => false,
            'is_default' => false,
            'sort' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(['name' => 'My Wishlist', 'slug' => 'my-wishlist', 'is_default' => true]);
    }
}
