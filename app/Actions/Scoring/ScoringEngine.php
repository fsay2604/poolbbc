<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use App\Models\DraftPick;
use App\Models\EventResult;
use App\Models\PointEntry;
use Illuminate\Support\Collection;

class ScoringEngine
{
    public function score(EventResult $result): void
    {
        $result->loadMissing('event.options.houseguest', 'options.houseguest');
        $event = $result->event;

        if (in_array($event->mode, [EventMode::Roster, EventMode::Hybrid], true)) {
            $this->scoreRoster($result);
        }

        if (in_array($event->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
            $this->scorePredictions($result);
        }
    }

    private function scoreRoster(EventResult $result): void
    {
        $event = $result->event;
        $houseguestIds = $result->options->pluck('houseguest_id')->filter()->values();
        $pointsPerMatch = (int) data_get($event->scoring_config, 'owner.points_per_match', 0);

        if ($houseguestIds->isEmpty() || $pointsPerMatch === 0) {
            return;
        }

        DraftPick::query()
            ->where('pool_id', $event->pool_id)
            ->whereIn('houseguest_id', $houseguestIds)
            ->with('houseguest')
            ->get()
            ->groupBy('pool_member_id')
            ->each(function (Collection $picks, int $poolMemberId) use ($result, $pointsPerMatch): void {
                $names = $picks->pluck('houseguest.name')->filter()->implode(', ');
                PointEntry::query()->firstOrCreate(
                    ['idempotency_key' => "result:{$result->id}:member:{$poolMemberId}:roster"],
                    [
                        'pool_member_id' => $poolMemberId,
                        'event_id' => $result->event_id,
                        'event_result_id' => $result->id,
                        'type' => 'roster',
                        'points' => $picks->count() * $pointsPerMatch,
                        'reason' => __('Roster result: :names', ['names' => $names]),
                    ],
                );
            });
    }

    private function scorePredictions(EventResult $result): void
    {
        $event = $result->event;
        $resultOptionIds = $result->options->pluck('id')->sort()->values();
        $pointsPerCorrect = (int) data_get($event->scoring_config, 'prediction.points_per_correct', 0);
        $exactBonus = (int) data_get($event->scoring_config, 'prediction.exact_match_bonus', 0);
        $wrongPenalty = (int) data_get($event->scoring_config, 'prediction.wrong_answer_penalty', 0);
        $allowNegative = (bool) data_get($event->scoring_config, 'allow_negative', false);

        $event->predictions()
            ->whereIn('status', ['submitted', 'locked'])
            ->with('options')
            ->get()
            ->each(function ($prediction) use ($result, $resultOptionIds, $pointsPerCorrect, $exactBonus, $wrongPenalty, $allowNegative): void {
                $selectedIds = $prediction->options->pluck('id')->sort()->values();
                $correct = $selectedIds->intersect($resultOptionIds)->count();
                $wrong = $selectedIds->diff($resultOptionIds)->count();
                $isExact = $selectedIds->all() === $resultOptionIds->all();
                $points = ($correct * $pointsPerCorrect) + ($wrong * $wrongPenalty) + ($isExact ? $exactBonus : 0);
                $points = $allowNegative ? $points : max(0, $points);

                PointEntry::query()->firstOrCreate(
                    ['idempotency_key' => "result:{$result->id}:prediction:{$prediction->id}"],
                    [
                        'pool_member_id' => $prediction->pool_member_id,
                        'event_id' => $result->event_id,
                        'event_result_id' => $result->id,
                        'event_prediction_id' => $prediction->id,
                        'type' => 'prediction',
                        'points' => $points,
                        'reason' => __('Prediction: :correct correct, :wrong incorrect:exact', [
                            'correct' => $correct,
                            'wrong' => $wrong,
                            'exact' => $isExact ? __(', exact match') : '',
                        ]),
                    ],
                );
            });
    }
}
