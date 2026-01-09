<?php

use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

test('creating a season auto-creates 16 weeks starting the second Sunday of January', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('admin.seasons.index')
        ->set('form.name', 'Season 2026')
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $season = Season::query()->where('name', 'Season 2026')->firstOrFail();

    expect($season->weeks()->count())->toBe(16);

    $week1 = $season->weeks()->where('number', 1)->firstOrFail();
    expect($week1->starts_at->toDateTimeString())->toBe('2026-01-11 00:00:00');
    expect($week1->auto_lock_at->toDateTimeString())->toBe('2026-01-17 19:00:00');
    expect($week1->ends_at->toDateTimeString())->toBe('2026-01-18 00:00:00');

    $week16 = $season->weeks()->where('number', 16)->firstOrFail();
    expect($week16->starts_at->toDateTimeString())->toBe('2026-04-26 00:00:00');
    expect($week16->auto_lock_at->toDateTimeString())->toBe('2026-05-02 19:00:00');
    expect($week16->ends_at->toDateTimeString())->toBe('2026-05-03 00:00:00');
});
