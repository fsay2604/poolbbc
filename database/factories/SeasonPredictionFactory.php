<?php

namespace Database\Factories;

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeasonPrediction>
 */
class SeasonPredictionFactory extends Factory
{
    /**
     * @var class-string<\App\Models\SeasonPrediction>
     */
    protected $model = SeasonPrediction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->for($season)->count(8)->create();

        return [
            'season_id' => $season->id,
            'user_id' => User::factory(),
            'winner_houseguest_id' => $houseguests[0]->id,
            'first_evicted_houseguest_id' => $houseguests[1]->id,
            'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
            'confirmed_at' => null,
        ];
    }
}
