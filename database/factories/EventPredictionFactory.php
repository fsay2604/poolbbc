<?php

namespace Database\Factories;

use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\PoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventPrediction>
 */
class EventPredictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'pool_member_id' => PoolMember::factory(),
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ];
    }
}
