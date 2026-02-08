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
        ->set('form.phases.0.hoh_ids.0', $boss->id)
        ->set('form.phases.1.nominee_ids.0', $nominee1->id)
        ->set('form.phases.1.nominee_ids.1', $nominee2->id)
        ->set('form.phases.2.winner_ids.0', $vetoWinner->id)
        ->set('form.phases.3.evicted_ids.0', $evicted->id)
        ->call('confirm')
        ->assertHasNoErrors();

    $prediction = Prediction::query()
        ->where('week_id', $week->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($prediction->confirmed_at)->not->toBeNull();
    expect($prediction->phase_picks)->toBeArray();
    expect(collect($prediction->phase_picks)->firstWhere('type', 'veto')['veto_used'] ?? null)->toBeFalse();
    expect(collect($prediction->phase_picks)->firstWhere('type', 'veto')['saved_ids'] ?? null)->toBe([]);
    expect(collect($prediction->phase_picks)->firstWhere('type', 'veto')['replacement_ids'] ?? null)->toBe([]);
});

it('clears saved and replacement picks when veto is toggled off after being used', function () {
    Carbon::setTestNow('2026-01-06 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
    ]);

    $vetoWinner = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $saved = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $replacement = Houseguest::factory()->for($season)->create(['is_active' => true]);

    $this->actingAs($user);

    $component = Livewire::test('weeks.show', ['week' => $week])
        ->set('form.phases.2.winner_ids.0', $vetoWinner->id)
        ->set('form.phases.2.veto_used', true)
        ->set('form.phases.2.saved_ids.0', $saved->id)
        ->set('form.phases.2.replacement_ids.0', $replacement->id)
        ->call('save')
        ->assertHasNoErrors();

    $component
        ->set('form.phases.2.veto_used', false)
        ->call('save')
        ->assertHasNoErrors();

    $prediction = Prediction::query()
        ->where('week_id', $week->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    $vetoPhase = collect($prediction->phase_picks)->firstWhere('type', 'veto');

    expect($vetoPhase['veto_used'] ?? null)->toBeFalse();
    expect($vetoPhase['saved_ids'] ?? null)->toBe([]);
    expect($vetoPhase['replacement_ids'] ?? null)->toBe([]);
});
