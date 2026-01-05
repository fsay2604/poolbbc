<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Livewire\Volt\Volt;

test('admin recalculate also scores season predictions', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $week16 = Week::factory()->for($season)->create(['number' => 16]);
    WeekOutcome::factory()->for($week16)->create();

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $season->forceFill([
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ])->save();

    $user = User::factory()->create();

    $prediction = SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $user->id,
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('admin.recalculate')
        ->call('recalculate');

    expect(SeasonPredictionScore::query()->where('season_prediction_id', $prediction->id)->exists())
        ->toBeTrue();
});
