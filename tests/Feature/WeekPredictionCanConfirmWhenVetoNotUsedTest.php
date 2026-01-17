<?php

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('can confirm a week prediction when veto is not used', function () {
    Carbon::setTestNow('2026-01-06 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'boss_count' => 1,
        'nominee_count' => 2,
        'evicted_count' => 1,
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
    ]);

    $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $nominee1 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $nominee2 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $vetoWinner = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $evicted = Houseguest::factory()->for($season)->create(['is_active' => true]);

    $this->actingAs($user);

    Livewire::test('weeks.show', ['week' => $week])
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.nominee_houseguest_ids.0', $nominee1->id)
        ->set('form.nominee_houseguest_ids.1', $nominee2->id)
        ->set('form.veto_winner_houseguest_id', $vetoWinner->id)
        ->set('form.veto_used', '0')
        ->assertSet('form.veto_used', '0')
        ->set('form.saved_houseguest_id', null)
        ->set('form.replacement_nominee_houseguest_id', null)
        ->set('form.evicted_houseguest_ids.0', $evicted->id)
        ->call('confirm')
        ->assertHasNoErrors();

    $prediction = Prediction::query()
        ->where('week_id', $week->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($prediction->confirmed_at)->not->toBeNull();
    expect($prediction->veto_used)->toBeFalse();
    expect($prediction->saved_houseguest_id)->toBeNull();
    expect($prediction->replacement_nominee_houseguest_id)->toBeNull();
});
