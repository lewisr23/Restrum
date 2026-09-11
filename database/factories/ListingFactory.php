<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Fender', 'Gibson', 'Yamaha', 'Roland', 'Boss'])
                .' '.fake()->randomElement(['Stratocaster', 'Les Paul', 'SM58', 'Juno-106', 'DD-7']),
            'description' => fake()->sentence(12),
            'price' => fake()->randomFloat(2, 40, 2000),
            'location' => fake()->randomElement(['Newcastle', 'Leeds', 'Manchester', 'Bristol']),
            'category' => fake()->randomElement(['GUITAR', 'DRUMS', 'MICROPHONE', 'SYNTHS', 'AUDIO_EQUIPMENT']),
            'condition' => fake()->randomElement(['MINT', 'EXCELLENT', 'GOOD', 'FAIR']),
            'status' => 'ACTIVE',
            'seller_id' => User::factory(),
        ];
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'SOLD',
        ]);
    }
}
