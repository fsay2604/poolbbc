<?php

namespace Database\Factories;

use App\Models\Prediction;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Prediction>
 */
class PredictionFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Prediction>
     */
    protected $model = Prediction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'week_id' => Week::factory(),
            'user_id' => User::factory(),
            'hoh_houseguest_id' => null,
            'nominee_1_houseguest_id' => null,
            'nominee_2_houseguest_id' => null,
            'veto_winner_houseguest_id' => null,
            'veto_used' => null,
            'saved_houseguest_id' => null,
            'replacement_nominee_houseguest_id' => null,
            'evicted_houseguest_id' => null,
            'confirmed_at' => null,
            'last_admin_edited_by_user_id' => null,
            'last_admin_edited_at' => null,
            'admin_edit_count' => 0,
        ];
    }
}
