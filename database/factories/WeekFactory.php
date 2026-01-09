<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Week>
 */
class WeekFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Week>
     */
    protected $model = Week::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = Carbon::now();

        return [
            'season_id' => Season::factory(),
            'number' => $this->faker->numberBetween(1, 30),
            'name' => null,
            'is_locked' => true,
            'auto_lock_at' => $now->copy()->addDays(2),
            'starts_at' => $now->copy()->addDay(),
            'ends_at' => $now->copy()->addDays(7),
        ];
    }
}
