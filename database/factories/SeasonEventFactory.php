<?php

namespace Database\Factories;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\ResultPublicationMode;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonEvent>
 */
class SeasonEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_round_id' => SeasonRound::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'question' => fake()->sentence(),
            'answer_source' => AnswerSource::Houseguests,
            'status' => EventStatus::Draft,
            'position' => fake()->unique()->numberBetween(1, 1000),
            'opens_at' => now()->addHour(),
            'locks_at' => now()->addDay(),
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
            'result_publication_mode' => ResultPublicationMode::Immediate,
            'default_mode' => EventMode::Hybrid,
            'scoring_config' => [
                'owner' => ['points_per_match' => 1],
                'prediction' => [
                    'points_per_correct' => 1,
                    'exact_match_bonus' => 0,
                    'wrong_answer_penalty' => 0,
                ],
                'allow_negative' => false,
            ],
        ];
    }
}
