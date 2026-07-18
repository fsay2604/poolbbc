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
            'phase_picks' => null,
            'confirmed_at' => null,
            'last_admin_edited_by_user_id' => null,
            'last_admin_edited_at' => null,
            'admin_edit_count' => 0,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }
}
