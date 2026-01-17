<?php

namespace App\Actions\Dashboard;

use App\Models\Houseguest;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;

class BuildDashboardStats
{
    private int $cacheMinutes = 5;

    /**
     * @return array{
     *     season: ?\App\Models\Season,
     *     houseguests: \Illuminate\Support\Collection<int, \App\Models\Houseguest>,
     *     statistics: \Illuminate\Support\Collection<int, array{
     *         user: ?\App\Models\User,
     *         user_name: string,
     *         earned: int,
     *         possible: int,
     *         accuracy: int,
     *         series: array<int, int>
     *     }>,
     *     houseguestSexStatistics: array{male_percent:int, female_percent:int, total:int},
     *     houseguestOccupationStatistics: \Illuminate\Support\Collection<int, array{occupation:string, count:int, percent:int}>
     * }
     */
    public function handle(): array
    {
        $season = Season::query()->where('is_active', true)->first();

        if (app()->environment('testing')) {
            return $this->build($season);
        }

        $cacheKey = $this->cacheKey($season);
        $ttl = now()->addMinutes($this->cacheMinutes);

        return Cache::remember($cacheKey, $ttl, fn () => $this->build($season));
    }

    public function forget(?Season $season): void
    {
        Cache::forget($this->cacheKey($season));
    }

    private function cacheKey(?Season $season): string
    {
        if ($season === null) {
            return 'dashboard.stats.none';
        }

        return "dashboard.stats.season.{$season->id}";
    }

    /**
     * @return array{
     *     season: ?\App\Models\Season,
     *     houseguests: \Illuminate\Support\Collection<int, \App\Models\Houseguest>,
     *     statistics: \Illuminate\Support\Collection<int, array{
     *         user: ?\App\Models\User,
     *         user_name: string,
     *         earned: int,
     *         possible: int,
     *         accuracy: int,
     *         series: array<int, int>
     *     }>,
     *     houseguestSexStatistics: array{male_percent:int, female_percent:int, total:int},
     *     houseguestOccupationStatistics: \Illuminate\Support\Collection<int, array{occupation:string, count:int, percent:int}>
     * }
     */
    private function build(?Season $season): array
    {
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

                        $user = $userScores->first()->user;

                        return [
                            'user' => $user,
                            'user_name' => $user?->name ?? '--',
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

        return [
            'season' => $season,
            'houseguests' => $houseguests,
            'statistics' => $statistics,
            'houseguestSexStatistics' => $houseguestSexStatistics,
            'houseguestOccupationStatistics' => $houseguestOccupationStatistics,
        ];
    }
}
