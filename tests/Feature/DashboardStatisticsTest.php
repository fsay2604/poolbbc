<?php

declare(strict_types=1);

use App\Actions\Predictions\ScoreWeek;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;

test('dashboard shows user prediction accuracy statistics', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $hoh = Houseguest::factory()->create(['season_id' => $season->id]);
    $nominee1 = Houseguest::factory()->create(['season_id' => $season->id]);
    $nominee2 = Houseguest::factory()->create(['season_id' => $season->id]);
    $vetoWinner = Houseguest::factory()->create(['season_id' => $season->id]);
    $evicted = Houseguest::factory()->create(['season_id' => $season->id]);

    $week = Week::factory()->create([
        'season_id' => $season->id,
        'number' => 1,
        'boss_count' => 1,
        'nominee_count' => 2,
        'evicted_count' => 1,
    ]);

    WeekOutcome::factory()->create([
        'week_id' => $week->id,
        'hoh_houseguest_id' => $hoh->id,
        'nominee_1_houseguest_id' => $nominee1->id,
        'nominee_2_houseguest_id' => $nominee2->id,
        'veto_winner_houseguest_id' => $vetoWinner->id,
        'veto_used' => false,
        'evicted_houseguest_id' => $evicted->id,
    ]);

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    Prediction::factory()->create([
        'week_id' => $week->id,
        'user_id' => $alice->id,
        'hoh_houseguest_id' => $hoh->id,
        'nominee_1_houseguest_id' => $nominee1->id,
        'nominee_2_houseguest_id' => $nominee2->id,
        'veto_winner_houseguest_id' => $vetoWinner->id,
        'veto_used' => false,
        'evicted_houseguest_id' => $evicted->id,
    ]);

    Prediction::factory()->create([
        'week_id' => $week->id,
        'user_id' => $bob->id,
    ]);

    app(ScoreWeek::class)->run($week);

    $this->actingAs($alice);

    $this->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('Statistics')
        ->assertSee('Alice')
        ->assertSee('100%')
        ->assertSee('Bob')
        ->assertSee('0%');
});
