<?php

use App\Models\Season;
use Database\Seeders\CurrentYearSeasonSeeder;
use Illuminate\Support\Carbon;

test('current year season seeder creates a season and 12 default weeks', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->seed(CurrentYearSeasonSeeder::class);

    $season = Season::query()->where('name', 'Season 2026')->firstOrFail();
    expect($season->is_active)->toBeTrue();
    expect($season->weeks()->count())->toBe(12);
    expect($season->weeks()->where('number', 1)->firstOrFail()->starts_at->toDateString())->toBe('2026-01-11');
});
