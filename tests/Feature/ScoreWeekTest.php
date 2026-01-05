<?php

use App\Actions\Predictions\ScoreWeek;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;

it('calculates and stores weekly scores when an outcome exists', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 1]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $hoh = Houseguest::factory()->for($season)->create();
    $nominee1 = Houseguest::factory()->for($season)->create();
    $nominee2 = Houseguest::factory()->for($season)->create();
    $vetoWinner = Houseguest::factory()->for($season)->create();
    $saved = Houseguest::factory()->for($season)->create();
    $replacement = Houseguest::factory()->for($season)->create();
    $evicted = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()
        ->for($week)
        ->for($user)
        ->create([
            'hoh_houseguest_id' => $hoh->id,
            'nominee_1_houseguest_id' => $nominee1->id,
            'nominee_2_houseguest_id' => $nominee2->id,
            'veto_winner_houseguest_id' => $vetoWinner->id,
            'veto_used' => true,
            'saved_houseguest_id' => $saved->id,
            'replacement_nominee_houseguest_id' => $replacement->id,
            'evicted_houseguest_id' => $evicted->id,
        ]);

    WeekOutcome::factory()->for($week)->create([
        'hoh_houseguest_id' => $hoh->id,
        'nominee_1_houseguest_id' => $nominee1->id,
        'nominee_2_houseguest_id' => $nominee2->id,
        'veto_winner_houseguest_id' => $vetoWinner->id,
        'veto_used' => true,
        'saved_houseguest_id' => $saved->id,
        'replacement_nominee_houseguest_id' => $replacement->id,
        'evicted_houseguest_id' => $evicted->id,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(8);
    expect($score->breakdown)
        ->toHaveKeys(['hoh', 'nominees_points', 'veto_winner', 'veto_used', 'saved', 'replacement', 'evicted']);
});

it('does not create scores if a week has no outcome', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 1]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $prediction = Prediction::factory()->for($week)->for($user)->create();

    app(ScoreWeek::class)->run($week, $admin);

    expect(PredictionScore::query()->where('prediction_id', $prediction->id)->exists())->toBeFalse();
});

it('scores dynamic nominees and evicted counts using json arrays', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create([
        'number' => 2,
        'nominee_count' => 3,
        'evicted_count' => 2,
    ]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $hoh = Houseguest::factory()->for($season)->create();
    $nominee1 = Houseguest::factory()->for($season)->create();
    $nominee2 = Houseguest::factory()->for($season)->create();
    $nominee3 = Houseguest::factory()->for($season)->create();
    $vetoWinner = Houseguest::factory()->for($season)->create();
    $saved = Houseguest::factory()->for($season)->create();
    $replacement = Houseguest::factory()->for($season)->create();
    $evicted1 = Houseguest::factory()->for($season)->create();
    $evicted2 = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()
        ->for($week)
        ->for($user)
        ->create([
            'hoh_houseguest_id' => $hoh->id,
            'nominee_houseguest_ids' => [$nominee1->id, $nominee2->id, $nominee3->id],
            'nominee_1_houseguest_id' => null,
            'nominee_2_houseguest_id' => null,
            'veto_winner_houseguest_id' => $vetoWinner->id,
            'veto_used' => true,
            'saved_houseguest_id' => $saved->id,
            'replacement_nominee_houseguest_id' => $replacement->id,
            'evicted_houseguest_ids' => [$evicted1->id, $evicted2->id],
            'evicted_houseguest_id' => null,
        ]);

    WeekOutcome::factory()->for($week)->create([
        'hoh_houseguest_id' => $hoh->id,
        'nominee_houseguest_ids' => [$nominee1->id, $nominee2->id, $nominee3->id],
        'nominee_1_houseguest_id' => null,
        'nominee_2_houseguest_id' => null,
        'veto_winner_houseguest_id' => $vetoWinner->id,
        'veto_used' => true,
        'saved_houseguest_id' => $saved->id,
        'replacement_nominee_houseguest_id' => $replacement->id,
        'evicted_houseguest_ids' => [$evicted1->id, $evicted2->id],
        'evicted_houseguest_id' => null,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(10);
    expect($score->breakdown['nominees_points'])->toBe(3);
    expect($score->breakdown['evicted_points'])->toBe(2);
    expect($score->breakdown['evicted'])->toBeNull();
});

it('scores dynamic bosses using json arrays', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create([
        'number' => 3,
        'boss_count' => 2,
    ]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $boss1 = Houseguest::factory()->for($season)->create();
    $boss2 = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()
        ->for($week)
        ->for($user)
        ->create([
            'boss_houseguest_ids' => [$boss1->id, $boss2->id],
            'hoh_houseguest_id' => null,
        ]);

    WeekOutcome::factory()->for($week)->create([
        'boss_houseguest_ids' => [$boss1->id, $boss2->id],
        'hoh_houseguest_id' => null,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(2);
    expect($score->breakdown['boss_points'])->toBe(2);
    expect($score->breakdown['hoh'])->toBeNull();
});
