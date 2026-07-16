<?php

namespace Database\Factories;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventType>
 */
class EventTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => null,
            'name' => fake()->words(3, true),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']).'-'.fake()->unique()->numberBetween(1, 99999),
            'is_standard' => false,
            'default_mode' => EventMode::Prediction,
            'answer_source' => AnswerSource::Houseguests,
            'default_config' => [],
        ];
    }
}
