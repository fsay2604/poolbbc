<?php

namespace Database\Factories;

use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoolInvitation>
 */
class PoolInvitationFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'code' => Str::random(40),
            'status' => 'pending',
            'expires_at' => now()->addWeek(),
        ];
    }
}
