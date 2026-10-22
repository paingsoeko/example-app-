<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'default_radius' => fake()->randomElement([50, 100, 200, 500]),
        ];
    }
}
