<?php

namespace Database\Factories;

use App\Enums\DraftStatus;
use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Draft>
 */
class DraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'status' => DraftStatus::Pending,
            'current_pick_number' => 0,
        ];
    }
}
