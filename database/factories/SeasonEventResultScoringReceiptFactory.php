<?php

namespace Database\Factories;

use App\Models\PoolEvent;
use App\Models\SeasonEventResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonEventResultScoringReceipt>
 */
class SeasonEventResultScoringReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_event_result_id' => SeasonEventResult::factory(),
            'pool_event_id' => function (array $attributes): int {
                $result = SeasonEventResult::query()->findOrFail($attributes['season_event_result_id']);

                return PoolEvent::factory()->create([
                    'season_event_id' => $result->season_event_id,
                ])->id;
            },
            'completed_at' => null,
        ];
    }
}
