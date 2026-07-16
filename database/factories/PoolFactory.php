<?php

namespace Database\Factories;

use App\Enums\DraftMode;
use App\Enums\PoolStatus;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pool>
 */
class PoolFactory extends Factory
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
            'owner_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'invite_code' => Str::upper(fake()->unique()->bothify('????####')),
            'timezone' => 'America/Toronto',
            'status' => PoolStatus::Registration,
            'max_members' => 12,
            'picks_per_member' => 2,
            'draft_mode' => DraftMode::Snake,
            'exclusive_draft' => true,
        ];
    }
}
