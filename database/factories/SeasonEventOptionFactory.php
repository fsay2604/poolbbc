<?php

namespace Database\Factories;

use App\Models\SeasonEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonEventOption>
 */
class SeasonEventOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->word();

        return [
            'season_event_id' => SeasonEvent::factory(),
            'houseguest_id' => null,
            'label' => $label,
            'value' => Str::slug($label).':'.fake()->unique()->numberBetween(1, 100000),
            'position' => fake()->unique()->numberBetween(1, 1000),
            'is_none' => false,
        ];
    }
}
