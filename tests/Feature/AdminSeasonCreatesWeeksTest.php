<?php

use App\Actions\Weeks\WeekPhaseManager;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * @return array<string, int|string|null>
 */
function adminWeekPhaseRow(int $position, string $type): array
{
    return [
        'id' => null,
        'position' => $position,
        'type' => $type,
        'hoh_count' => $type === WeekPhaseManager::TYPE_HOH ? 1 : 0,
        'nominee_count' => $type === WeekPhaseManager::TYPE_NOMINEES ? 2 : 0,
        'winner_count' => $type === WeekPhaseManager::TYPE_VETO ? 1 : 0,
        'saved_count' => $type === WeekPhaseManager::TYPE_VETO ? 1 : 0,
        'replacement_count' => $type === WeekPhaseManager::TYPE_VETO ? 1 : 0,
        'evicted_count' => $type === WeekPhaseManager::TYPE_EVICTIONS ? 1 : 0,
    ];
}

test('creating a season auto-creates 12 weeks starting the second Sunday of January', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('admin.seasons.index')
        ->set('form.name', 'Season 2026')
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $season = Season::query()->where('name', 'Season 2026')->firstOrFail();

    expect($season->weeks()->count())->toBe(12);

    $week1 = $season->weeks()->where('number', 1)->firstOrFail();
    expect($week1->starts_at->toDateTimeString())->toBe('2026-01-11 00:00:00');
    expect($week1->auto_lock_at->toDateTimeString())->toBe('2026-01-17 19:00:00');
    expect($week1->ends_at->toDateTimeString())->toBe('2026-01-18 00:00:00');
    $week1->load('phases');
    expect($week1->phases->pluck('type')->all())->toBe(['hoh', 'nominees', 'veto', 'evictions']);

    $week12 = $season->weeks()->where('number', 12)->firstOrFail();
    expect($week12->starts_at->toDateTimeString())->toBe('2026-03-29 00:00:00');
    expect($week12->auto_lock_at->toDateTimeString())->toBe('2026-04-04 19:00:00');
    expect($week12->ends_at->toDateTimeString())->toBe('2026-04-05 00:00:00');
});

test('admin can create a week with repeated phase sequence 1,2,3,2,4', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $admin = User::factory()->admin()->create();
    $season = Season::factory()->create(['is_active' => true]);
    $this->actingAs($admin);

    Livewire::test('admin.weeks.index')
        ->set('form.number', 1)
        ->set('form.phases', [
            adminWeekPhaseRow(1, WeekPhaseManager::TYPE_HOH),
            adminWeekPhaseRow(2, WeekPhaseManager::TYPE_NOMINEES),
            adminWeekPhaseRow(3, WeekPhaseManager::TYPE_VETO),
            adminWeekPhaseRow(4, WeekPhaseManager::TYPE_NOMINEES),
            adminWeekPhaseRow(5, WeekPhaseManager::TYPE_EVICTIONS),
        ])
        ->call('save')
        ->assertHasNoErrors();

    $week = $season->weeks()->where('number', 1)->firstOrFail();
    $week->load('phases');

    expect($week->phases->pluck('type')->all())->toBe([
        WeekPhaseManager::TYPE_HOH,
        WeekPhaseManager::TYPE_NOMINEES,
        WeekPhaseManager::TYPE_VETO,
        WeekPhaseManager::TYPE_NOMINEES,
        WeekPhaseManager::TYPE_EVICTIONS,
    ]);
});

test('admin can create a week with repeated phase sequence 1,2,3,1,2,3,4', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $admin = User::factory()->admin()->create();
    $season = Season::factory()->create(['is_active' => true]);
    $this->actingAs($admin);

    Livewire::test('admin.weeks.index')
        ->set('form.number', 1)
        ->set('form.phases', [
            adminWeekPhaseRow(1, WeekPhaseManager::TYPE_HOH),
            adminWeekPhaseRow(2, WeekPhaseManager::TYPE_NOMINEES),
            adminWeekPhaseRow(3, WeekPhaseManager::TYPE_VETO),
            adminWeekPhaseRow(4, WeekPhaseManager::TYPE_HOH),
            adminWeekPhaseRow(5, WeekPhaseManager::TYPE_NOMINEES),
            adminWeekPhaseRow(6, WeekPhaseManager::TYPE_VETO),
            adminWeekPhaseRow(7, WeekPhaseManager::TYPE_EVICTIONS),
        ])
        ->call('save')
        ->assertHasNoErrors();

    $week = $season->weeks()->where('number', 1)->firstOrFail();
    $week->load('phases');

    expect($week->phases->pluck('type')->all())->toBe([
        WeekPhaseManager::TYPE_HOH,
        WeekPhaseManager::TYPE_NOMINEES,
        WeekPhaseManager::TYPE_VETO,
        WeekPhaseManager::TYPE_HOH,
        WeekPhaseManager::TYPE_NOMINEES,
        WeekPhaseManager::TYPE_VETO,
        WeekPhaseManager::TYPE_EVICTIONS,
    ]);
});
