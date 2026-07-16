<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventResult;
use App\Models\PoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PointEntry>
 */
class PointEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_member_id' => PoolMember::factory(),
            'event_id' => Event::factory(),
            'event_result_id' => EventResult::factory(),
            'type' => 'prediction',
            'points' => fake()->numberBetween(0, 10),
            'reason' => fake()->sentence(),
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }
}
