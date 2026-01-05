<?php

namespace App\Actions\Predictions;

use App\Models\Season;
use App\Models\SeasonPrediction;

class ScoreSeasonPrediction
{
    /**
     * @return array{points:int, breakdown:array<string, mixed>}
     */
    public function score(SeasonPrediction $prediction, Season $season): array
    {
        $points = 0;

        $winnerCorrect = $this->idMatches($prediction->winner_houseguest_id, $season->winner_houseguest_id);
        $points += $winnerCorrect ? 16 : 0;

        $firstEvictedCorrect = $this->idMatches($prediction->first_evicted_houseguest_id, $season->first_evicted_houseguest_id);
        $points += $firstEvictedCorrect ? 16 : 0;

        $predictedTop6 = $this->normalizeIdList($prediction->top_6_houseguest_ids);
        $actualTop6 = $this->normalizeIdList($season->top_6_houseguest_ids);

        $top6CorrectCount = count(array_intersect($predictedTop6, $actualTop6));
        $top6Points = $top6CorrectCount * 2;
        $points += $top6Points;

        return [
            'points' => $points,
            'breakdown' => [
                'winner' => $winnerCorrect,
                'first_evicted' => $firstEvictedCorrect,
                'top_6_correct_count' => $top6CorrectCount,
                'top_6_points' => $top6Points,
            ],
        ];
    }

    private function idMatches(?int $predictedId, ?int $actualId): bool
    {
        return $predictedId !== null && $actualId !== null && $predictedId === $actualId;
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($id): ?int => is_numeric($id) ? (int) $id : null,
            $value,
        )));

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
