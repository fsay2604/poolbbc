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

        $nomineesPoints = $this->nomineesPoints(
            $prediction->nominee_1_houseguest_id,
            $prediction->nominee_2_houseguest_id,
            $outcome->nominee_1_houseguest_id,
            $outcome->nominee_2_houseguest_id,
        );
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

        $evictedCorrect = $this->idMatches($prediction->evicted_houseguest_id, $outcome->evicted_houseguest_id);
        $points += $evictedCorrect ? 1 : 0;

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

    private function nomineesPoints(?int $p1, ?int $p2, ?int $a1, ?int $a2): int
    {
        if ($a1 === null || $a2 === null || $p1 === null || $p2 === null) {
            return 0;
        }

        $predicted = array_values(array_unique([$p1, $p2]));
        $actual = array_values(array_unique([$a1, $a2]));

        return count(array_intersect($predicted, $actual));
    }
}
