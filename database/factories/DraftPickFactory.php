<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\Houseguest;
use App\Models\PoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DraftPick>
 */
class DraftPickFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draft_id' => Draft::factory(),
            'pool_id' => fn (array $attributes) => Draft::query()->findOrFail($attributes['draft_id'])->pool_id,
            'pool_member_id' => PoolMember::factory(),
            'houseguest_id' => Houseguest::factory(),
            'round_number' => 1,
            'pick_number' => 1,
            'exclusive_claim' => 1,
            'picked_at' => now(),
        ];
    }
}
