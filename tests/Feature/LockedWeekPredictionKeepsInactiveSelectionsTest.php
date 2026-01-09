<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

it('keeps locked selections visible even if houseguest becomes inactive', function () {
    Carbon::setTestNow('2026-01-05 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'prediction_deadline_at' => Carbon::parse('2026-01-10 19:00:00'),
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
    ]);

    $inactiveSelected = Houseguest::factory()->for($season)->create([
        'is_active' => false,
        'name' => 'Evicted Player',
    ]);

    $activeOther = Houseguest::factory()->for($season)->create([
        'is_active' => true,
        'name' => 'Active Player',
    ]);

    Prediction::factory()->for($week)->for($user)->create([
        'hoh_houseguest_id' => $inactiveSelected->id,
        'confirmed_at' => now(),
    ]);

    $this->actingAs($user);

    Volt::test('weeks.show', ['week' => $week])
        ->assertSee('Evicted Player')
        ->assertSee('Active Player');
});
