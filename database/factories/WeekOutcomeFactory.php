<?php

namespace Database\Factories;

use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WeekOutcome>
 */
class WeekOutcomeFactory extends Factory
{
    /**
     * @var class-string<\App\Models\WeekOutcome>
     */
    protected $model = WeekOutcome::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'week_id' => Week::factory(),
            'hoh_houseguest_id' => null,
            'nominee_1_houseguest_id' => null,
            'nominee_2_houseguest_id' => null,
            'veto_winner_houseguest_id' => null,
            'veto_used' => null,
            'saved_houseguest_id' => null,
            'replacement_nominee_houseguest_id' => null,
            'evicted_houseguest_id' => null,
            'last_admin_edited_by_user_id' => null,
            'last_admin_edited_at' => null,
        ];
    }
}
