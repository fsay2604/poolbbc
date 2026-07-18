<?php

namespace Database\Factories;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\ResultPublicationMode;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'pool_id' => fn (array $attributes) => Round::query()->findOrFail($attributes['round_id'])->pool_id,
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'question' => fake()->sentence(),
            'mode' => EventMode::Prediction,
            'answer_source' => AnswerSource::Houseguests,
            'status' => EventStatus::Open,
            'position' => 1,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addDay(),
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
            'result_publication_mode' => ResultPublicationMode::Immediate,
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ];
    }
}
