<?php

use App\Models\Houseguest;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::get('dashboard', function () {
    $season = Season::query()->where('is_active', true)->first();

    $houseguests = Houseguest::query()
        ->when($season, fn ($q) => $q->where('season_id', $season->id), fn ($q) => $q->whereRaw('1=0'))
        ->orderBy('sort_order')
        ->orderBy('name')
        ->get();

    $statistics = collect();

    $houseguestSexStatistics = [
        'male_percent' => 0,
        'female_percent' => 0,
        'total' => 0,
    ];

    $houseguestOccupationStatistics = collect();

    if ($houseguests->isNotEmpty()) {
        $totalHouseguests = $houseguests->count();

        $maleCount = $houseguests->where('sex', 'M')->count();
        $femaleCount = $houseguests->where('sex', 'F')->count();
        $total = $maleCount + $femaleCount;

        if ($total > 0) {
            $malePercent = (int) round(($maleCount / $total) * 100);
            $femalePercent = 100 - $malePercent;
        } else {
            $malePercent = 0;
            $femalePercent = 0;
        }

        $houseguestSexStatistics = [
            'male_percent' => $malePercent,
            'female_percent' => $femalePercent,
            'total' => $total,
        ];

        $occupationCounts = $houseguests
            ->map(function (Houseguest $houseguest): string {
                if (! is_array($houseguest->occupations)) {
                    return __('Unknown');
                }

                $occupation = collect($houseguest->occupations)
                    ->filter(fn ($value) => is_string($value) && $value !== '')
                    ->first();

                return is_string($occupation) ? $occupation : __('Unknown');
            })
            ->countBy();

        $houseguestOccupationStatistics = $occupationCounts
            ->map(function (int $count, string $occupation) use ($totalHouseguests): array {
                $percent = $totalHouseguests > 0
                    ? (int) round(($count / $totalHouseguests) * 100)
                    : 0;

                return [
                    'occupation' => $occupation,
                    'count' => $count,
                    'percent' => $percent,
                ];
            })
            ->sortBy([
                ['percent', 'desc'],
                ['occupation', 'asc'],
            ])
            ->values();
    }

    if ($season !== null) {
        $weeksWithOutcomes = Week::query()
            ->where('season_id', $season->id)
            ->with('outcome')
            ->get()
            ->filter(fn (Week $week) => $week->outcome !== null)
            ->sortBy('number')
            ->values();

        $weekMaxPoints = $weeksWithOutcomes
            ->mapWithKeys(function (Week $week): array {
                $outcome = $week->outcome;
                if ($outcome === null) {
                    return [];
                }

                $bosses = is_array($outcome->boss_houseguest_ids) && $outcome->boss_houseguest_ids !== []
                    ? array_values(array_filter($outcome->boss_houseguest_ids))
                    : array_values(array_filter([$outcome->hoh_houseguest_id]));

                $nominees = is_array($outcome->nominee_houseguest_ids) && $outcome->nominee_houseguest_ids !== []
                    ? array_values(array_filter($outcome->nominee_houseguest_ids))
                    : array_values(array_filter([$outcome->nominee_1_houseguest_id, $outcome->nominee_2_houseguest_id]));

                $evicted = is_array($outcome->evicted_houseguest_ids) && $outcome->evicted_houseguest_ids !== []
                    ? array_values(array_filter($outcome->evicted_houseguest_ids))
                    : array_values(array_filter([$outcome->evicted_houseguest_id]));

                $maxPoints = 0;

                $maxPoints += count($bosses);
                $maxPoints += count($nominees);
                $maxPoints += $outcome->veto_winner_houseguest_id !== null ? 1 : 0;
                $maxPoints += $outcome->veto_used !== null ? 1 : 0;

                if ($outcome->veto_used === true) {
                    $maxPoints += $outcome->saved_houseguest_id !== null ? 1 : 0;
                    $maxPoints += $outcome->replacement_nominee_houseguest_id !== null ? 1 : 0;
                }

                $maxPoints += count($evicted);

                return [$week->id => $maxPoints];
            })
            ->filter(fn (int $maxPoints) => $maxPoints > 0);

        if ($weekMaxPoints->isNotEmpty()) {
            $scores = PredictionScore::query()
                ->with('user')
                ->whereIn('week_id', $weekMaxPoints->keys())
                ->whereHas('week', fn ($q) => $q->where('season_id', $season->id))
                ->get();

            $orderedWeekIds = $weeksWithOutcomes
                ->pluck('id')
                ->filter(fn (int $weekId) => $weekMaxPoints->has($weekId))
                ->values();

            $statistics = $scores
                ->groupBy('user_id')
                ->map(function ($userScores) use ($weekMaxPoints, $orderedWeekIds) {
                    $earned = (int) $userScores->sum('points');
                    $possible = (int) $userScores->sum(fn ($score) => $weekMaxPoints->get($score->week_id, 0));

                    $accuracy = $possible > 0
                        ? (int) round(($earned / $possible) * 100)
                        : 0;

                    $scoreByWeekId = $userScores->keyBy('week_id');

                    $series = $orderedWeekIds
                        ->map(function (int $weekId) use ($scoreByWeekId, $weekMaxPoints): int {
                            $max = (int) $weekMaxPoints->get($weekId, 0);
                            if ($max <= 0) {
                                return 0;
                            }

                            $earnedPoints = (int) ($scoreByWeekId->get($weekId)?->points ?? 0);

                            return (int) round(($earnedPoints / $max) * 100);
                        })
                        ->values()
                        ->all();

                    return [
                        'user_name' => $userScores->first()->user?->name ?? '—',
                        'earned' => $earned,
                        'possible' => $possible,
                        'accuracy' => $accuracy,
                        'series' => $series,
                    ];
                })
                ->values()
                ->sortByDesc('accuracy')
                ->sortByDesc('earned')
                ->values();
        }
    }

    return view('dashboard', [
        'season' => $season,
        'houseguests' => $houseguests,
        'statistics' => $statistics,
        'houseguestSexStatistics' => $houseguestSexStatistics,
        'houseguestOccupationStatistics' => $houseguestOccupationStatistics,
    ]);
})
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Volt::route('weeks', 'weeks.index')->name('weeks.index');
    Volt::route('weeks/{week}', 'weeks.show')->name('weeks.show');

    Volt::route('season-prediction', 'season-prediction')->name('season.prediction');

    Route::get('current-week', function () {
        $now = now();

        $week = Week::query()
            ->forActiveSeason()
            ->orderBy('number')
            ->where(function ($query) use ($now) {
                $query
                    ->where('is_locked', false)
                    ->where(fn ($query) => $query->whereNull('auto_lock_at')->orWhere('auto_lock_at', '>', $now));
            })
            ->first();

        $week ??= Week::query()->forActiveSeason()->orderByDesc('number')->first();

        abort_if($week === null, 404);

        return redirect()->route('weeks.show', $week);
    })->name('current-week');

    Volt::route('leaderboard', 'leaderboard')->name('leaderboard');
    Volt::route('leaderboard/{user}', 'leaderboard.show')->name('leaderboard.show');

    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        Volt::route('seasons', 'admin.seasons.index')->name('admin.seasons.index');
        Volt::route('season-outcome', 'admin.seasons.outcome')->name('admin.seasons.outcome');
        Volt::route('weeks', 'admin.weeks.index')->name('admin.weeks.index');
        Volt::route('houseguests', 'admin.houseguests.index')->name('admin.houseguests.index');
        Volt::route('weeks/{week}/outcome', 'admin.weeks.outcome')->name('admin.weeks.outcome');
        Volt::route('predictions/{prediction}', 'admin.predictions.edit')->name('admin.predictions.edit');
        Volt::route('recalculate', 'admin.recalculate')->name('admin.recalculate');
    });
});
