<?php

namespace Database\Factories;

use App\Enums\PredictionStatus;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoolEventPrediction>
 */
class PoolEventPredictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_event_id' => PoolEvent::factory(),
            'pool_member_id' => function (array $attributes): int {
                $poolEvent = PoolEvent::query()->findOrFail($attributes['pool_event_id']);

                return PoolMember::factory()->create(['pool_id' => $poolEvent->pool_id])->id;
            },
            'status' => PredictionStatus::Draft,
            'submitted_at' => null,
            'locked_at' => null,
        ];
    }
}
