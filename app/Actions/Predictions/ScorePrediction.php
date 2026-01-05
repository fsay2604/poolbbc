<?php

namespace App\Actions\Predictions;

use App\Models\Prediction;
use App\Models\WeekOutcome;

class ScorePrediction
{
    /**
     * @return array{points:int, breakdown:array<string, mixed>}
     */
    public function score(Prediction $prediction, WeekOutcome $outcome): array
    {
        $points = 0;

        $hohCorrect = $this->idMatches($prediction->hoh_houseguest_id, $outcome->hoh_houseguest_id);
        $points += $hohCorrect ? 1 : 0;

        $predictedNominees = $this->nomineesList($prediction);
        $actualNominees = $this->nomineesList($outcome);

        $nomineesPoints = $this->listIntersectionPoints($predictedNominees, $actualNominees);
        $points += $nomineesPoints;

        $vetoWinnerCorrect = $this->idMatches($prediction->veto_winner_houseguest_id, $outcome->veto_winner_houseguest_id);
        $points += $vetoWinnerCorrect ? 1 : 0;

        $vetoUsedCorrect = $this->boolMatches($prediction->veto_used, $outcome->veto_used);
        $points += $vetoUsedCorrect ? 1 : 0;

        $savedCorrect = null;
        $replacementCorrect = null;
        if ($outcome->veto_used === true) {
            $savedCorrect = $this->idMatches($prediction->saved_houseguest_id, $outcome->saved_houseguest_id);
            $replacementCorrect = $this->idMatches(
                $prediction->replacement_nominee_houseguest_id,
                $outcome->replacement_nominee_houseguest_id,
            );

            $points += $savedCorrect ? 1 : 0;
            $points += $replacementCorrect ? 1 : 0;
        }

        $predictedEvicted = $this->evictedList($prediction);
        $actualEvicted = $this->evictedList($outcome);

        $evictedPoints = $this->listIntersectionPoints($predictedEvicted, $actualEvicted);
        $points += $evictedPoints;

        $evictedCorrect = null;
        if (count($predictedEvicted) === 1 && count($actualEvicted) === 1) {
            $evictedCorrect = $predictedEvicted[0] === $actualEvicted[0];
        }

        return [
            'points' => $points,
            'breakdown' => [
                'hoh' => $hohCorrect,
                'nominees_points' => $nomineesPoints,
                'veto_winner' => $vetoWinnerCorrect,
                'veto_used' => $vetoUsedCorrect,
                'saved' => $savedCorrect,
                'replacement' => $replacementCorrect,
                'evicted' => $evictedCorrect,
                'evicted_points' => $evictedPoints,
            ],
        ];
    }

    private function idMatches(?int $predictedId, ?int $actualId): bool
    {
        return $predictedId !== null && $actualId !== null && $predictedId === $actualId;
    }

    private function boolMatches(?bool $predicted, ?bool $actual): bool
    {
        return $predicted !== null && $actual !== null && $predicted === $actual;
    }

    /**
     * @param  list<int>  $predicted
     * @param  list<int>  $actual
     */
    private function listIntersectionPoints(array $predicted, array $actual): int
    {
        if ($predicted === [] || $actual === []) {
            return 0;
        }

        return count(array_intersect($predicted, $actual));
    }

    /**
     * @return list<int>
     */
    private function nomineesList(Prediction|WeekOutcome $model): array
    {
        $ids = $this->normalizeIdList($model->nominee_houseguest_ids ?? null);
        if ($ids !== []) {
            return $ids;
        }

        return $this->normalizeIdList([
            $model->nominee_1_houseguest_id ?? null,
            $model->nominee_2_houseguest_id ?? null,
        ]);
    }

    /**
     * @return list<int>
     */
    private function evictedList(Prediction|WeekOutcome $model): array
    {
        $ids = $this->normalizeIdList($model->evicted_houseguest_ids ?? null);
        if ($ids !== []) {
            return $ids;
        }

        return $this->normalizeIdList([$model->evicted_houseguest_id ?? null]);
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($id): ?int => is_numeric($id) ? (int) $id : null,
            $value,
        )));

        return array_values(array_unique($ids));
    }
}
