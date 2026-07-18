<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('can save a week outcome when veto is not used', function () {
    Carbon::setTestNow('2026-01-06 12:00:00');

    $admin = User::factory()->admin()->create();
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

    $this->actingAs($admin);

    Livewire::test('admin.weeks.outcome', ['week' => $week])
        ->set('form.phases.0.hoh_ids.0', $boss->id)
        ->set('form.phases.1.nominee_ids.0', $nominee1->id)
        ->set('form.phases.1.nominee_ids.1', $nominee2->id)
        ->set('form.phases.2.winner_ids.0', $vetoWinner->id)
        ->set('form.phases.3.evicted_ids.0', $evicted->id)
        ->call('save')
        ->assertHasNoErrors();

    $outcome = WeekOutcome::query()->where('week_id', $week->id)->firstOrFail();

    expect($outcome->phase_results)->toBeArray();
    expect(collect($outcome->phase_results)->firstWhere('type', 'veto')['veto_used'] ?? null)->toBeFalse();
    expect(collect($outcome->phase_results)->firstWhere('type', 'veto')['saved_ids'] ?? null)->toBe([]);
    expect(collect($outcome->phase_results)->firstWhere('type', 'veto')['replacement_ids'] ?? null)->toBe([]);
});

it('clears saved and replacement results when veto is toggled off after being used', function () {
    Carbon::setTestNow('2026-01-06 12:00:00');

    $admin = User::factory()->admin()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
    ]);

    $vetoWinner = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $saved = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $replacement = Houseguest::factory()->for($season)->create(['is_active' => true]);

    $this->actingAs($admin);

    $component = Livewire::test('admin.weeks.outcome', ['week' => $week])
        ->set('form.phases.2.winner_ids.0', $vetoWinner->id)
        ->set('form.phases.2.veto_used', true)
        ->set('form.phases.2.saved_ids.0', $saved->id)
        ->set('form.phases.2.replacement_ids.0', $replacement->id)
        ->call('save')
        ->assertHasNoErrors();

    $component
        ->set('form.phases.2.veto_used', false)
        ->set('correctionReason', 'Le veto n’a finalement pas été utilisé.')
        ->call('save')
        ->assertHasNoErrors();

    $outcome = WeekOutcome::query()->where('week_id', $week->id)->firstOrFail();
    $vetoPhase = collect($outcome->phase_results)->firstWhere('type', 'veto');

    expect($vetoPhase['veto_used'] ?? null)->toBeFalse();
    expect($vetoPhase['saved_ids'] ?? null)->toBe([]);
    expect($vetoPhase['replacement_ids'] ?? null)->toBe([]);
});
