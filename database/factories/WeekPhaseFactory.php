<?php

namespace Database\Factories;

use App\Actions\Weeks\WeekPhaseManager;
use App\Models\Week;
use App\Models\WeekPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WeekPhase>
 */
class WeekPhaseFactory extends Factory
{
    /**
     * @var class-string<\App\Models\WeekPhase>
     */
    protected $model = WeekPhase::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'week_id' => Week::factory(),
            'position' => 1,
            'type' => WeekPhaseManager::TYPE_HOH,
            'config' => ['hoh_count' => 1],
        ];
    }
}
