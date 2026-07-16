<?php

namespace Database\Factories;

use App\Models\EventOption;
use App\Models\EventResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventResultSelection>
 */
class EventResultSelectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_result_id' => EventResult::factory(),
            'event_option_id' => EventOption::factory(),
        ];
    }
}
