<?php

namespace Database\Factories;

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PredictionScore>
 */
class PredictionScoreFactory extends Factory
{
    /**
     * @var class-string<\App\Models\PredictionScore>
     */
    protected $model = PredictionScore::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prediction_id' => Prediction::factory(),
            'week_id' => Week::factory(),
            'user_id' => User::factory(),
            'points' => 0,
            'breakdown' => null,
            'calculated_at' => null,
        ];
    }
}
