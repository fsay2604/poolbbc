<?php

namespace Database\Factories;

use App\Enums\EventMode;
use App\Models\Pool;
use App\Models\SeasonEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoolEvent>
 */
class PoolEventFactory extends Factory
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
            'pool_id' => function (array $attributes): int {
                $seasonEvent = SeasonEvent::query()->with('round')->findOrFail($attributes['season_event_id']);

                return Pool::factory()->create(['season_id' => $seasonEvent->round->season_id])->id;
            },
            'local_event_id' => null,
            'mode' => EventMode::Prediction,
            'is_active' => true,
            'visibility' => 'after_lock',
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
            'rules_customized_at' => null,
        ];
    }
}
