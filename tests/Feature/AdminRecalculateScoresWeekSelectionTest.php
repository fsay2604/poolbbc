<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Livewire\Livewire;

it('recalculates all weeks when triggered', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $season = Season::factory()->create(['is_active' => true]);

    $week1 = Week::factory()->for($season)->create(['number' => 1]);
    $week2 = Week::factory()->for($season)->create(['number' => 2]);

    $boss1 = Houseguest::factory()->for($season)->create();
    $boss2 = Houseguest::factory()->for($season)->create();

    $prediction1 = Prediction::factory()->for($week1)->for($user)->create([
        'hoh_houseguest_id' => $boss1->id,
    ]);
    $prediction2 = Prediction::factory()->for($week2)->for($user)->create([
        'hoh_houseguest_id' => $boss2->id,
    ]);

    WeekOutcome::factory()->for($week1)->create([
        'hoh_houseguest_id' => $boss1->id,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    WeekOutcome::factory()->for($week2)->create([
        'hoh_houseguest_id' => $boss2->id,
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test('admin.recalculate')
        ->call('recalculate')
        ->assertDispatched('scores-recalculated');

    expect(PredictionScore::query()->where('prediction_id', $prediction1->id)->exists())->toBeTrue();
    expect(PredictionScore::query()->where('prediction_id', $prediction2->id)->exists())->toBeTrue();
});
