<?php

declare(strict_types=1);

use App\Actions\Seasons\CalculateSeasonOutcomeFromWeekOutcomes;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\Week;
use App\Models\WeekOutcome;

it('calculates first evicted, top 6, and winner from week outcomes', function () {
    $season = Season::factory()->create();

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

    // Evict 8,7,6,5,4,3,2 -> remaining is 1 (winner)
    $evictionOrder = [$houseguests[7]->id, $houseguests[6]->id, $houseguests[5]->id, $houseguests[4]->id, $houseguests[3]->id, $houseguests[2]->id, $houseguests[1]->id];

    foreach ($weeks as $index => $week) {
        WeekOutcome::factory()->for($week)->create([
            'evicted_houseguest_ids' => [$evictionOrder[$index]],
            'evicted_houseguest_id' => $evictionOrder[$index],
        ]);
    }

    (new CalculateSeasonOutcomeFromWeekOutcomes)->execute($season->refresh());

    $season->refresh();

    expect($season->first_evicted_houseguest_id)->toBe($houseguests[7]->id);
    expect($season->top_6_houseguest_ids)->toBe([
        $houseguests[0]->id,
        $houseguests[1]->id,
        $houseguests[2]->id,
        $houseguests[3]->id,
        $houseguests[4]->id,
        $houseguests[5]->id,
    ]);
    expect($season->winner_houseguest_id)->toBe($houseguests[0]->id);
});
