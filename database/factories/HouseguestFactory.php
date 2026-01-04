<?php

namespace Database\Factories;

use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Houseguest>
 */
class HouseguestFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Houseguest>
     */
    protected $model = Houseguest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'name' => $this->faker->unique()->firstName(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
