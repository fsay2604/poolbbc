<?php

declare(strict_types=1);

use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;

test('leaderboard includes season scores in totals', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $user = User::factory()->create(['name' => 'Season Scorer']);
    $admin = User::factory()->admin()->create();

    $week = Week::factory()->for($season)->create(['number' => 1]);

    $week16 = Week::factory()->for($season)->create(['number' => 16]);
    WeekOutcome::factory()->for($week16)->create();

    $prediction = Prediction::factory()->for($week)->for($user)->create();
    PredictionScore::factory()->create([
        'prediction_id' => $prediction->id,
        'week_id' => $week->id,
        'user_id' => $user->id,
        'points' => 8,
        'breakdown' => ['week' => true],
        'calculated_at' => now(),
    ]);

    $houseguests = Houseguest::factory()->for($season)->count(8)->create(['is_active' => true]);

    $season->forceFill([
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ])->save();

    SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $user->id,
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => [
            $houseguests[0]->id,
            $houseguests[1]->id,
            $houseguests[2]->id,
            $houseguests[3]->id,
            $houseguests[4]->id,
            $houseguests[7]->id,
        ],
    ]);

    app(ScoreSeasonPredictions::class)->run($season, $admin);

    $this->actingAs($user);

    $response = $this->get(route('leaderboard'));

    $response->assertOk();
    $response->assertSee('Season');
    $response->assertSee('Season Scorer');

    // Total = weekly (8) + season (42)
    $response->assertSee('50');
    $response->assertSee('42');
    $response->assertSee('8');
});
