<?php

namespace Database\Factories;

use App\Models\Pool;
use App\Models\PoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoolScoreProjection>
 */
class PoolScoreProjectionFactory extends Factory
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
            'pool_member_id' => function (array $attributes): int {
                return PoolMember::factory()->create(['pool_id' => $attributes['pool_id']])->id;
            },
            'total_points' => fake()->numberBetween(0, 100),
            'last_point_entry_id' => null,
            'rebuilt_at' => now(),
        ];
    }
}
