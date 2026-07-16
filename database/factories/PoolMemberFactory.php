<?php

namespace Database\Factories;

use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoolMember>
 */
class PoolMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'user_id' => User::factory(),
            'role' => PoolMemberRole::Member,
            'status' => PoolMemberStatus::Active,
            'draft_position' => 1,
            'joined_at' => now(),
        ];
    }
}
