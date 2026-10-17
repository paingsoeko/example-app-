<?php

namespace Database\Factories;

use App\Models\TestRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestRecord>
 */
class TestRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
        ];
    }
}
