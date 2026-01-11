<?php

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Database\Seeders\FullSeasonSeeder;
use Illuminate\Support\Carbon;

test('full season seeder creates a complete season with users, predictions, and outcomes', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->seed(FullSeasonSeeder::class);

    $season = Season::query()->where('name', 'Full Season 2026')->firstOrFail();
    expect($season->is_active)->toBeTrue();

    expect(User::query()->where('email', 'fullseason1@example.com')->where('is_admin', true)->exists())->toBeTrue();
    expect(User::query()->whereIn('email', collect(range(1, 15))->map(fn (int $i) => 'fullseason'.$i.'@example.com')->all())->count())
        ->toBe(15);

    $weeks = Week::query()->where('season_id', $season->id)->orderBy('number')->get();
    expect($weeks)->toHaveCount(12);

    expect(WeekOutcome::query()->whereIn('week_id', $weeks->pluck('id'))->count())->toBe(12);
    expect(Prediction::query()->whereIn('week_id', $weeks->pluck('id'))->count())->toBe(15 * 12);
    expect(Prediction::query()->whereIn('week_id', $weeks->pluck('id'))->whereNotNull('confirmed_at')->count())->toBe(15 * 12);

    expect(PredictionScore::query()->whereIn('week_id', $weeks->pluck('id'))->count())->toBe(15 * 12);
    expect(SeasonPrediction::query()->where('season_id', $season->id)->count())->toBe(15);
    expect(SeasonPredictionScore::query()->where('season_id', $season->id)->count())->toBe(15);

    expect($season->houseguests()->where('is_active', true)->count())->toBe(16);
    expect(
        $season->houseguests()
            ->whereNotNull('occupations')
            ->get()
            ->every(fn ($houseguest) => is_array($houseguest->occupations) && $houseguest->occupations !== [])
    )->toBeTrue();
});
