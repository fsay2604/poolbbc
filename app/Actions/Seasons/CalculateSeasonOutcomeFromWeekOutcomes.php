<?php

namespace App\Actions\Seasons;

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\Week;

class CalculateSeasonOutcomeFromWeekOutcomes
{
    public function execute(Season $season): void
    {
        $weeks = Week::query()
            ->where('season_id', $season->id)
            ->with('outcome')
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

            $evictedIds = $this->normalizeEvictedIds($outcome->evicted_houseguest_ids, $outcome->evicted_houseguest_id);

            if ($week->number === 1 && $firstEvictedHouseguestId === null && $evictedIds !== []) {
                $firstEvictedHouseguestId = $evictedIds[0];
            }

            if ($evictedIds !== []) {
                $remainingHouseguestIds = array_values(array_diff($remainingHouseguestIds, $evictedIds));
            }

            if ($top6HouseguestIds === null && count($remainingHouseguestIds) === 6) {
                $top6HouseguestIds = $remainingHouseguestIds;
            }

            if ($winnerHouseguestId === null && count($remainingHouseguestIds) === 1) {
                $winnerHouseguestId = $remainingHouseguestIds[0];
            }
        }

        $shouldSave = false;

        if ($firstEvictedHouseguestId !== null && $season->first_evicted_houseguest_id !== $firstEvictedHouseguestId) {
            $season->first_evicted_houseguest_id = $firstEvictedHouseguestId;
            $shouldSave = true;
        }

        if ($top6HouseguestIds !== null && $season->top_6_houseguest_ids !== $top6HouseguestIds) {
            $season->top_6_houseguest_ids = $top6HouseguestIds;
            $shouldSave = true;
        }

        if ($winnerHouseguestId !== null && $season->winner_houseguest_id !== $winnerHouseguestId) {
            $season->winner_houseguest_id = $winnerHouseguestId;
            $shouldSave = true;
        }

        if ($shouldSave) {
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
