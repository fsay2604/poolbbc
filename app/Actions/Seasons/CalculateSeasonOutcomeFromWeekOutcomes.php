<?php

namespace App\Actions\Seasons;

use App\Actions\Weeks\WeekPhaseManager;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\Week;

class CalculateSeasonOutcomeFromWeekOutcomes
{
    public WeekPhaseManager $weekPhaseManager;

    public function __construct(?WeekPhaseManager $weekPhaseManager = null)
    {
        $this->weekPhaseManager = $weekPhaseManager ?? app(WeekPhaseManager::class);
    }

    public function execute(Season $season): void
    {
        $weeks = Week::query()
            ->where('season_id', $season->id)
            ->with(['outcome', 'phases'])
            ->orderBy('number')
            ->get();

        $remainingHouseguestIds = Houseguest::query()
            ->where('season_id', $season->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $firstEvictedHouseguestId = null;
        $top6HouseguestIds = null;
        $winnerHouseguestId = null;

        foreach ($weeks as $week) {
            $outcome = $week->outcome;

            if (! $outcome) {
                continue;
            }

            $evictedIds = $this->weekPhaseManager->evictedIds($outcome->phase_results);
            if ($evictedIds === []) {
                $evictedIds = $this->normalizeEvictedIds($outcome->evicted_houseguest_ids ?? null, $outcome->evicted_houseguest_id ?? null);
            }

            if ($firstEvictedHouseguestId === null && $evictedIds !== []) {
                $firstEvictedHouseguestId = $evictedIds[0];
            }

            foreach ($evictedIds as $evictedId) {
                $remainingHouseguestIds = array_values(array_diff($remainingHouseguestIds, [$evictedId]));

                if ($top6HouseguestIds === null && count($remainingHouseguestIds) === 6) {
                    $top6HouseguestIds = $remainingHouseguestIds;
                }

                if ($winnerHouseguestId === null && count($remainingHouseguestIds) === 1) {
                    $winnerHouseguestId = $remainingHouseguestIds[0];
                }
            }
        }

        $season->fill([
            'first_evicted_houseguest_id' => $firstEvictedHouseguestId,
            'top_6_houseguest_ids' => $top6HouseguestIds,
            'winner_houseguest_id' => $winnerHouseguestId,
        ]);

        if ($season->isDirty()) {
            $season->save();
        }
    }

    /**
     * @return list<int>
     */
    private function normalizeEvictedIds(mixed $evictedHouseguestIds, mixed $fallbackEvictedHouseguestId): array
    {
        $ids = [];

        if (is_array($evictedHouseguestIds)) {
            foreach ($evictedHouseguestIds as $id) {
                if (! is_numeric($id)) {
                    continue;
                }

                $intId = (int) $id;

                if (! in_array($intId, $ids, true)) {
                    $ids[] = $intId;
                }
            }
        }

        if ($ids === [] && is_numeric($fallbackEvictedHouseguestId)) {
            $ids[] = (int) $fallbackEvictedHouseguestId;
        }

        return $ids;
    }
}
