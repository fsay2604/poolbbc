<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Livewire\Livewire;

test('admin recalculate scores (all weeks) calculates season outcome and scores season predictions', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->sequence(fn ($sequence) => ['sort_order' => $sequence->index + 1])
        ->create()
        ->values();

    $weeks = Week::factory()
        ->for($season)
        ->count(7)
        ->sequence(fn ($sequence) => ['number' => $sequence->index + 1])
        ->create()
        ->values();

    // Evict 8,7,6,5,4,3,2 -> remaining is 1 (winner), top6 when remaining hits 6.
    $evictionOrder = [
        $houseguests[7]->id,
        $houseguests[6]->id,
        $houseguests[5]->id,
        $houseguests[4]->id,
        $houseguests[3]->id,
        $houseguests[2]->id,
        $houseguests[1]->id,
    ];

    foreach ($weeks as $index => $week) {
        WeekOutcome::factory()->for($week)->create([
            'evicted_houseguest_ids' => [$evictionOrder[$index]],
            'evicted_houseguest_id' => $evictionOrder[$index],
        ]);
    }

    $user = User::factory()->create();

    $prediction = SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $user->id,
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[7]->id,
        'top_6_houseguest_ids' => [
            $houseguests[0]->id,
            $houseguests[1]->id,
            $houseguests[2]->id,
            $houseguests[3]->id,
            $houseguests[4]->id,
            $houseguests[5]->id,
        ],
    ]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('admin.recalculate')
        ->call('recalculate');

    expect($season->refresh()->winner_houseguest_id)->toBe($houseguests[0]->id);
    expect($season->first_evicted_houseguest_id)->toBe($houseguests[7]->id);
    expect($season->top_6_houseguest_ids)->toBe([
        $houseguests[0]->id,
        $houseguests[1]->id,
        $houseguests[2]->id,
        $houseguests[3]->id,
        $houseguests[4]->id,
        $houseguests[5]->id,
    ]);

    expect(SeasonPredictionScore::query()->where('season_prediction_id', $prediction->id)->exists())
        ->toBeTrue();
});
