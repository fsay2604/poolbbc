<?php

namespace Tests\Feature;

use App\Models\Season;
use Database\Seeders\CurrentYearSeasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CurrentYearSeasonSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_year_seeder_creates_the_season_without_legacy_weeks(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        try {
            $this->seed(CurrentYearSeasonSeeder::class);

            $season = Season::query()->where('name', 'Season 2026')->firstOrFail();
            $this->assertTrue($season->is_active);
            $this->assertSame('2026-01-01', $season->starts_on->toDateString());
            $this->assertFalse(Schema::hasTable('weeks'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
