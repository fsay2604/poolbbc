<?php

namespace Database\Factories;

use App\Models\SeasonEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonEventResult>
 */
class SeasonEventResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_event_id' => SeasonEvent::factory(),
            'created_by' => User::factory(),
            'supersedes_id' => null,
            'version' => 1,
            'status' => 'published',
            'correction_reason' => null,
            'published_at' => now(),
        ];
    }
}
