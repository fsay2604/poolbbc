<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Season>
 */
class SeasonFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Season>
     */
    protected $model = Season::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Season '.$this->faker->year(),
            'is_active' => false,
            'starts_on' => $this->faker->date(),
            'ends_on' => null,
        ];
    }
}
