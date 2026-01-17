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

    $this->actingAs($admin);

    Livewire::test('admin.weeks.outcome', ['week' => $week])
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.nominee_houseguest_ids.0', $nominee1->id)
        ->set('form.nominee_houseguest_ids.1', $nominee2->id)
        ->set('form.veto_winner_houseguest_id', $vetoWinner->id)
        ->set('form.veto_used', '0')
        ->set('form.saved_houseguest_id', null)
        ->set('form.replacement_nominee_houseguest_id', null)
        ->set('form.evicted_houseguest_ids.0', $evicted->id)
        ->call('save')
        ->assertHasNoErrors();

    $outcome = WeekOutcome::query()->where('week_id', $week->id)->firstOrFail();

    expect($outcome->veto_used)->toBeFalse();
    expect($outcome->saved_houseguest_id)->toBeNull();
    expect($outcome->replacement_nominee_houseguest_id)->toBeNull();
});
