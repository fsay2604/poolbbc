<?php

declare(strict_types=1);

use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('can clear auto lock at when editing a week', function () {
    Carbon::setTestNow('2026-01-06 12:00:00');

    $admin = User::factory()->admin()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week = Week::factory()->for($season)->create([
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
        'starts_at' => Carbon::parse('2026-01-04 00:00:00'),
        'ends_at' => Carbon::parse('2026-01-11 00:00:00'),
    ]);

    $this->actingAs($admin);

    Livewire::test('admin.weeks.index')
        ->call('edit', $week->id)
        ->set('form.auto_lock_at', '')
        ->set('form.starts_at', '')
        ->set('form.ends_at', '')
        ->call('save')
        ->assertHasNoErrors();

    $week->refresh();

    expect($week->auto_lock_at)->toBeNull();
    expect($week->starts_at)->toBeNull();
    expect($week->ends_at)->toBeNull();
});
