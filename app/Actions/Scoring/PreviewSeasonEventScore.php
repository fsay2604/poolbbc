<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Models\PoolEvent;
use App\Models\SeasonEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PreviewSeasonEventScore
{
    /**
     * @param  list<int>  $optionIds
     * @return Collection<int, array{pool:string,member:string,roster_points:int,prediction_points:int,total_points:int}>
     */
    public function handle(SeasonEvent $event, User $administrator, array $optionIds): Collection
    {
        Gate::forUser($administrator)->authorize('recordResult', $event);

        $event = SeasonEvent::query()->findOrFail($event->id);
        if (! in_array($event->effectiveStatus(), [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published], true)) {
            throw ValidationException::withMessages([
                'result' => __('Result previews are unavailable before the official event is locked.'),
            ]);
        }

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        $options = $event->options()->whereKey($optionIds)->get();
        if ($options->count() !== count($optionIds)
            || count($optionIds) < $event->result_min_selections
            || count($optionIds) > $event->result_max_selections) {
            throw ValidationException::withMessages(['result' => __('The official result selection is invalid.')]);
        }

        if (count($optionIds) > 1 && $options->contains('is_none', true)) {
            throw ValidationException::withMessages([
                'result' => __('The explicit none option cannot be combined with another answer.'),
            ]);
        }

        $resultOptionIds = $options->pluck('id')->sort()->values();
        $houseguestIds = $options->pluck('houseguest_id')->filter()->values();

        return $event->poolEvents()
            ->where('is_active', true)
            ->with([
                'pool.competitionMembers.user',
                'pool.competitionMembers.draftPicks',
                'predictions.options',
            ])
            ->get()
            ->flatMap(function (PoolEvent $poolEvent) use ($resultOptionIds, $houseguestIds): Collection {
                return $poolEvent->pool->competitionMembers->map(function ($member) use ($poolEvent, $resultOptionIds, $houseguestIds): array {
                    $rosterPoints = 0;
                    if (in_array($poolEvent->mode, [EventMode::Roster, EventMode::Hybrid], true)) {
                        $rosterPoints = $member->draftPicks->whereIn('houseguest_id', $houseguestIds)->count()
                            * (int) data_get($poolEvent->scoring_config, 'owner.points_per_match', 0);
                    }

                    $predictionPoints = 0;
                    if (in_array($poolEvent->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
                        $prediction = $poolEvent->predictions
                            ->where('pool_member_id', $member->id)
                            ->first(fn ($prediction) => in_array($prediction->status->value, ['submitted', 'locked'], true));

                        if ($prediction !== null) {
                            $selectedIds = $prediction->options->pluck('id')->sort()->values();
                            $correct = $selectedIds->intersect($resultOptionIds)->count();
                            $wrong = $selectedIds->diff($resultOptionIds)->count();
                            $predictionPoints = ($correct * (int) data_get($poolEvent->scoring_config, 'prediction.points_per_correct', 0))
                                + ($wrong * (int) data_get($poolEvent->scoring_config, 'prediction.wrong_answer_penalty', 0))
                                + ($selectedIds->all() === $resultOptionIds->all()
                                    ? (int) data_get($poolEvent->scoring_config, 'prediction.exact_match_bonus', 0)
                                    : 0);

                        }
                    }

                    $totalPoints = $rosterPoints + $predictionPoints;
                    if (! data_get($poolEvent->scoring_config, 'allow_negative', false)) {
                        $totalPoints = max(0, $totalPoints);
                    }

                    return [
                        'pool' => $poolEvent->pool->name,
                        'member' => $member->user->name,
                        'roster_points' => $rosterPoints,
                        'prediction_points' => $predictionPoints,
                        'total_points' => $totalPoints,
                    ];
                });
            })
            ->values();
    }
}
