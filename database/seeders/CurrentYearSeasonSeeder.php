<?php

namespace Database\Seeders;

use App\Actions\Seasons\CreateDefaultWeeks;
use App\Models\Season;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CurrentYearSeasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $year = now()->year;

        $season = Season::query()->firstOrCreate(
            ['name' => 'Season '.$year],
            [
                'is_active' => true,
                'starts_on' => Carbon::create($year, 1, 1),
                'ends_on' => null,
            ]
        );

        Season::query()->where('id', '!=', $season->id)->update(['is_active' => false]);

        $season->forceFill([
            'is_active' => true,
            'starts_on' => $season->starts_on ?? Carbon::create($year, 1, 1),
        ])->save();

        app(CreateDefaultWeeks::class)->run($season);
    }
}
