<?php

namespace Database\Factories;

use App\Models\EventOption;
use App\Models\EventPrediction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventPredictionSelection>
 */
class EventPredictionSelectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_prediction_id' => EventPrediction::factory(),
            'event_option_id' => EventOption::factory(),
        ];
    }
}
