<?php

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;

test('demo seeder creates a season with two users, predictions, outcomes, and scores', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->seed(DemoSeeder::class);

    $season = Season::query()->where('name', 'Demo Season 2026')->firstOrFail();
    expect($season->is_active)->toBeTrue();

    expect(User::query()->whereIn('email', ['demo1@example.com', 'demo2@example.com'])->count())->toBe(2);
    expect(User::query()->where('email', 'admin@example.com')->where('is_admin', true)->exists())->toBeTrue();

    $week1 = Week::query()->where('season_id', $season->id)->where('number', 1)->firstOrFail();
    $week2 = Week::query()->where('season_id', $season->id)->where('number', 2)->firstOrFail();

    expect($week1->outcome()->exists())->toBeTrue();
    expect($week2->outcome()->exists())->toBeTrue();

    expect(Prediction::query()->where('week_id', $week1->id)->count())->toBe(2);
    expect(Prediction::query()->where('week_id', $week2->id)->count())->toBe(2);

    expect(Prediction::query()->where('week_id', $week1->id)->whereNotNull('confirmed_at')->count())->toBe(2);
    expect(Prediction::query()->where('week_id', $week2->id)->whereNotNull('confirmed_at')->count())->toBe(2);

    expect(PredictionScore::query()->where('week_id', $week1->id)->count())->toBe(2);
    expect(PredictionScore::query()->where('week_id', $week2->id)->count())->toBe(2);
});
