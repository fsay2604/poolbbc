<?php

use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;

it('calculates and stores season scores when season outcomes exist', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $week16 = Week::factory()->for($season)->create(['number' => 16]);
    WeekOutcome::factory()->for($week16)->create();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $houseguests = Houseguest::factory()->for($season)->count(8)->create();

    $season->forceFill([
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ])->save();

    $prediction = SeasonPrediction::factory()->create([
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

    $score = SeasonPredictionScore::query()->where('season_prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(42);
    expect($score->breakdown)
        ->toHaveKeys(['winner', 'first_evicted', 'top_6_correct_count', 'top_6_points']);
});

it('does not create scores if a season has no outcomes set', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $week16 = Week::factory()->for($season)->create(['number' => 16]);
    WeekOutcome::factory()->for($week16)->create();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $prediction = SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $user->id,
    ]);

    app(ScoreSeasonPredictions::class)->run($season, $admin);

    expect(SeasonPredictionScore::query()->where('season_prediction_id', $prediction->id)->exists())->toBeFalse();
});

it('does not create scores until week 16 has an outcome', function () {
    $season = Season::factory()->create(['is_active' => true]);

    // Week 16 exists but no outcome yet.
    Week::factory()->for($season)->create(['number' => 16]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $houseguests = Houseguest::factory()->for($season)->count(8)->create();

    $season->forceFill([
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ])->save();

    $prediction = SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $user->id,
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ]);

    app(ScoreSeasonPredictions::class)->run($season, $admin);

    expect(SeasonPredictionScore::query()->where('season_prediction_id', $prediction->id)->exists())->toBeFalse();
});
