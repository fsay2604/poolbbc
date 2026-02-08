<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Models\Week;

test('users can view prediction details for another user', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->sequence(
            ['name' => 'Winner', 'sex' => 'F', 'occupations' => ['Actor']],
            ['name' => 'First Evicted', 'sex' => 'M', 'occupations' => ['Teacher']],
            ['name' => 'Boss', 'sex' => 'M', 'occupations' => ['Doctor']],
            ['name' => 'Nominee One', 'sex' => 'F', 'occupations' => ['Artist']],
            ['name' => 'Nominee Two', 'sex' => 'M', 'occupations' => ['Chef']],
            ['name' => 'Veto Winner', 'sex' => 'F', 'occupations' => ['Writer']],
            ['name' => 'Saved', 'sex' => 'F', 'occupations' => ['Designer']],
            ['name' => 'Evicted', 'sex' => 'M', 'occupations' => ['Gamer']],
        )
        ->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create(['number' => 1]);

    $predictedUser = User::factory()->create(['name' => 'Alice']);
    $viewer = User::factory()->create();

    Prediction::factory()->create([
        'week_id' => $week->id,
        'user_id' => $predictedUser->id,
        'hoh_houseguest_id' => $houseguests[2]->id,
        'nominee_1_houseguest_id' => $houseguests[3]->id,
        'nominee_2_houseguest_id' => $houseguests[4]->id,
        'veto_winner_houseguest_id' => $houseguests[5]->id,
        'veto_used' => true,
        'saved_houseguest_id' => $houseguests[6]->id,
        'replacement_nominee_houseguest_id' => $houseguests[7]->id,
        'evicted_houseguest_id' => $houseguests[7]->id,
    ]);

    SeasonPrediction::factory()->create([
        'season_id' => $season->id,
        'user_id' => $predictedUser->id,
        'winner_houseguest_id' => $houseguests[0]->id,
        'first_evicted_houseguest_id' => $houseguests[1]->id,
        'top_6_houseguest_ids' => $houseguests->take(6)->pluck('id')->all(),
    ]);

    $weekLabel = __('Week').' '.$week->number;

    $this->actingAs($viewer)
        ->get(route('predictions.show', $predictedUser))
        ->assertSuccessful()
        ->assertSee(__('Predictions'))
        ->assertSee('Alice')
        ->assertSee($weekLabel)
        ->assertSee('Winner')
        ->assertSee('First Evicted')
        ->assertSee('Boss')
        ->assertSee('Nominee One')
        ->assertSee('Nominee Two')
        ->assertSee('Veto Winner')
        ->assertSee('Evicted');
});
