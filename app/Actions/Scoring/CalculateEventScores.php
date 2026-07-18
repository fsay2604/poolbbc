<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use Illuminate\Support\Collection;

class CalculateEventScores
{
    /**
     * @param  array<string, mixed>  $scoringConfig
     * @param  Collection<int, Collection<int, string>>  $rosterNamesByMember
     * @param  Collection<int, array{prediction_id: int, pool_member_id: int, selected_option_ids: list<int>}>  $predictions
     * @param  Collection<int, int>  $resultOptionIds
     * @return Collection<int, array{pool_member_id: int, prediction_id: int|null, type: string, points: int, reason: string}>
     */
    public function handle(
        EventMode $mode,
        array $scoringConfig,
        Collection $rosterNamesByMember,
        Collection $predictions,
        Collection $resultOptionIds,
    ): Collection {
        $entries = collect();

        if (in_array($mode, [EventMode::Roster, EventMode::Hybrid], true)) {
            $pointsPerMatch = (int) data_get($scoringConfig, 'owner.points_per_match', 0);

            if ($pointsPerMatch !== 0) {
                $rosterNamesByMember->each(function (Collection $names, int $poolMemberId) use ($entries, $pointsPerMatch): void {
                    $entries->push([
                        'pool_member_id' => $poolMemberId,
                        'prediction_id' => null,
                        'type' => 'roster',
                        'points' => $names->count() * $pointsPerMatch,
                        'reason' => __('Roster result: :names', ['names' => $names->implode(', ')]),
                    ]);
                });
            }
        }

        if (in_array($mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
            $normalizedResultOptionIds = $resultOptionIds
                ->map(fn (mixed $optionId): int => (int) $optionId)
                ->unique()
                ->sort()
                ->values();
            $pointsPerCorrect = (int) data_get($scoringConfig, 'prediction.points_per_correct', 0);
            $exactBonus = (int) data_get($scoringConfig, 'prediction.exact_match_bonus', 0);
            $wrongPenalty = (int) data_get($scoringConfig, 'prediction.wrong_answer_penalty', 0);

            $predictions->each(function (array $prediction) use ($entries, $normalizedResultOptionIds, $pointsPerCorrect, $exactBonus, $wrongPenalty): void {
                $selectedIds = collect($prediction['selected_option_ids'])
                    ->map(fn (mixed $optionId): int => (int) $optionId)
                    ->unique()
                    ->sort()
                    ->values();
                $correct = $selectedIds->intersect($normalizedResultOptionIds)->count();
                $wrong = $selectedIds->diff($normalizedResultOptionIds)->count();
                $isExact = $selectedIds->all() === $normalizedResultOptionIds->all();

                $entries->push([
                    'pool_member_id' => $prediction['pool_member_id'],
                    'prediction_id' => $prediction['prediction_id'],
                    'type' => 'prediction',
                    'points' => ($correct * $pointsPerCorrect) + ($wrong * $wrongPenalty) + ($isExact ? $exactBonus : 0),
                    'reason' => __('Prediction: :correct correct, :wrong incorrect:exact', [
                        'correct' => $correct,
                        'wrong' => $wrong,
                        'exact' => $isExact ? __(', exact match') : '',
                    ]),
                ]);
            });
        }

        if (! (bool) data_get($scoringConfig, 'allow_negative', false)) {
            $entries
                ->groupBy('pool_member_id')
                ->each(function (Collection $memberEntries, int $poolMemberId) use ($entries): void {
                    $total = (int) $memberEntries->sum('points');

                    if ($total < 0) {
                        $entries->push([
                            'pool_member_id' => $poolMemberId,
                            'prediction_id' => null,
                            'type' => 'adjustment',
                            'points' => -$total,
                            'reason' => __('Negative totals are not allowed for this event.'),
                        ]);
                    }
                });
        }

        return $entries->values();
    }
}
