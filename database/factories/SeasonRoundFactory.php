<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonRound>
 */
class SeasonRoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'name' => fake()->words(2, true),
            'position' => fake()->unique()->numberBetween(1, 1000),
            'status' => 'draft',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ];
    }
}
