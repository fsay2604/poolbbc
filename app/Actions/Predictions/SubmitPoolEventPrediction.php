<?php

namespace App\Actions\Predictions;

use App\Actions\Events\TransitionSeasonEvent;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Enums\PredictionStatus;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\SeasonEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPoolEventPrediction
{
    public function __construct(private TransitionSeasonEvent $transitionSeasonEvent) {}

    /** @param list<int> $optionIds */
    public function handle(PoolEvent $poolEvent, PoolMember $member, array $optionIds, bool $submit = true): PoolEventPrediction
    {
        $predictionsAreClosed = false;
        $prediction = DB::transaction(function () use ($poolEvent, $member, $optionIds, $submit, &$predictionsAreClosed): ?PoolEventPrediction {
            $pool = Pool::query()->lockForUpdate()->findOrFail($poolEvent->pool_id);
            $poolEvent = PoolEvent::query()->lockForUpdate()->findOrFail($poolEvent->id);
            $member = PoolMember::query()->lockForUpdate()->findOrFail($member->id);

            if ($poolEvent->season_event_id === null
                || ! $poolEvent->is_active
                || $poolEvent->pool_id !== $pool->id
                || in_array($pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)
                || $member->pool_id !== $poolEvent->pool_id
                || $member->status !== PoolMemberStatus::Active
                || ! in_array($poolEvent->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
                throw ValidationException::withMessages(['prediction' => __('This pool event does not accept predictions.')]);
            }

            $poolEvent->setRelation('pool', $pool);
            $seasonEvent = SeasonEvent::query()->lockForUpdate()->findOrFail($poolEvent->season_event_id);
            $seasonEvent = $this->transitionSeasonEvent->synchronize($seasonEvent);

            if ($seasonEvent->effectiveStatus() !== EventStatus::Open) {
                $predictionsAreClosed = true;

                return null;
            }

            $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
            $validOptions = $seasonEvent->options()->whereKey($optionIds)->get(['id', 'is_none']);
            if ($validOptions->count() !== count($optionIds)) {
                throw ValidationException::withMessages(['prediction' => __('One or more selected options are invalid.')]);
            }

            if (count($optionIds) > 1 && $validOptions->contains('is_none', true)) {
                throw ValidationException::withMessages([
                    'prediction' => __('The explicit none option cannot be combined with another answer.'),
                ]);
            }

            $count = count($optionIds);
            if (($submit && $count < $poolEvent->prediction_min_selections)
                || $count > $poolEvent->prediction_max_selections) {
                throw ValidationException::withMessages([
                    'prediction' => __('Select between :min and :max answers.', [
                        'min' => $poolEvent->prediction_min_selections,
                        'max' => $poolEvent->prediction_max_selections,
                    ]),
                ]);
            }

            $prediction = PoolEventPrediction::query()->firstOrNew([
                'pool_event_id' => $poolEvent->id,
                'pool_member_id' => $member->id,
            ]);
            $wasSubmitted = in_array($prediction->status, [PredictionStatus::Submitted, PredictionStatus::Locked], true);
            $remainsSubmitted = $submit || ($wasSubmitted && $count >= $poolEvent->prediction_min_selections);
            $prediction->status = $remainsSubmitted ? PredictionStatus::Submitted : PredictionStatus::Draft;
            $prediction->submitted_at = $remainsSubmitted ? ($prediction->submitted_at ?? now()) : null;
            $prediction->locked_at = null;
            $prediction->save();
            $prediction->options()->sync($optionIds);

            return $prediction->fresh('options');
        }, attempts: 3);

        if ($predictionsAreClosed || $prediction === null) {
            throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this official event.')]);
        }

        return $prediction;
    }
}
