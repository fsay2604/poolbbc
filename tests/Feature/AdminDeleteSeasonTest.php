<?php

use App\Actions\Predictions\ScoreWeek;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('admin can delete a season and all related data', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 1]);

    $hg1 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 1]);
    $hg2 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 2]);
    $hg3 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 3]);
    $hg4 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 4]);
    $hg5 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 5]);
    $hg6 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 6]);
    $hg7 = Houseguest::factory()->for($season)->create(['is_active' => true, 'sort_order' => 7]);

    $prediction = Prediction::factory()->for($week)->for($user)->create([
        'hoh_houseguest_id' => $hg1->id,
        'nominee_1_houseguest_id' => $hg2->id,
        'nominee_2_houseguest_id' => $hg3->id,
        'veto_winner_houseguest_id' => $hg4->id,
        'veto_used' => true,
        'saved_houseguest_id' => $hg2->id,
        'replacement_nominee_houseguest_id' => $hg5->id,
        'evicted_houseguest_id' => $hg5->id,
        'confirmed_at' => now(),
    ]);

    WeekOutcome::factory()->for($week)->create([
        'hoh_houseguest_id' => $hg1->id,
        'nominee_1_houseguest_id' => $hg2->id,
        'nominee_2_houseguest_id' => $hg3->id,
        'veto_winner_houseguest_id' => $hg4->id,
        'veto_used' => true,
        'saved_houseguest_id' => $hg2->id,
        'replacement_nominee_houseguest_id' => $hg5->id,
        'evicted_houseguest_id' => $hg5->id,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);
    expect(PredictionScore::query()->where('prediction_id', $prediction->id)->exists())->toBeTrue();

    $this->actingAs($admin);

    Livewire::test('admin.seasons.index')
        ->call('delete', $season->id)
        ->assertHasNoErrors();

    expect(Season::query()->whereKey($season->id)->exists())->toBeFalse();
    expect(Week::query()->whereKey($week->id)->exists())->toBeFalse();
    expect(Houseguest::query()->where('season_id', $season->id)->exists())->toBeFalse();
    expect(Prediction::query()->whereKey($prediction->id)->exists())->toBeFalse();
    expect(WeekOutcome::query()->where('week_id', $week->id)->exists())->toBeFalse();
    expect(PredictionScore::query()->where('week_id', $week->id)->exists())->toBeFalse();
});
