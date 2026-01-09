<?php

use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;

it('locks a week when it is forced locked (regardless of auto lock time)', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'prediction_deadline_at' => Carbon::parse('2026-01-10 12:00:00'),
        'is_locked' => true,
        'auto_lock_at' => Carbon::parse('2026-01-10 12:00:00'),
    ]);

    expect($week->isLocked())->toBeTrue();
});

it('locks a week when the auto lock time has passed', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-04 11:59:59'),
    ]);

    expect($week->isLocked())->toBeTrue();
});

it('locks a week when the auto lock time is exactly now', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-04 12:00:00'),
    ]);

    expect($week->isLocked())->toBeTrue();
});

it('does not lock a week before the auto lock time when forced unlocked', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-04 12:00:01'),
    ]);

    expect($week->isLocked())->toBeFalse();
});

it('current-week redirects to the earliest open week in the active season', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $openWeek2 = Week::factory()->for($season)->create([
        'number' => 2,
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-05 12:00:00'),
    ]);

    Week::factory()->for($season)->create([
        'number' => 1,
        'is_locked' => true,
        'auto_lock_at' => Carbon::parse('2026-01-04 10:00:00'),
    ]);

    $openWeek3 = Week::factory()->for($season)->create([
        'number' => 3,
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-06 12:00:00'),
    ]);

    $response = $this->actingAs($user)->get(route('current-week'));

    $response->assertRedirect(route('weeks.show', $openWeek2));
});

it('current-week falls back to the latest week when there are no open weeks', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    Week::factory()->for($season)->create([
        'number' => 1,
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-04 11:00:00'),
    ]);

    $latest = Week::factory()->for($season)->create([
        'number' => 2,
        'is_locked' => true,
        'auto_lock_at' => Carbon::parse('2026-01-04 10:00:00'),
    ]);

    $response = $this->actingAs($user)->get(route('current-week'));

    $response->assertRedirect(route('weeks.show', $latest));
});
