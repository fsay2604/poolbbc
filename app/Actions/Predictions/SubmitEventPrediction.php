<?php

namespace App\Actions\Predictions;

use App\Actions\Events\SynchronizeEventLifecycle;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\Pool;
use App\Models\PoolMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitEventPrediction
{
    public function __construct(private SynchronizeEventLifecycle $synchronizeEventLifecycle) {}

    /** @param list<int> $optionIds */
    public function handle(Event $event, PoolMember $member, array $optionIds, bool $submit = true): EventPrediction
    {
        $predictionsAreClosed = false;
        $prediction = DB::transaction(function () use ($event, $member, $optionIds, $submit, &$predictionsAreClosed): ?EventPrediction {
            $pool = Pool::query()->lockForUpdate()->findOrFail($event->pool_id);
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $member = PoolMember::query()->lockForUpdate()->findOrFail($member->id);

            if ($member->pool_id !== $event->pool_id
                || $event->pool_id !== $pool->id
                || $member->status !== PoolMemberStatus::Active
                || in_array($pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)) {
                throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this event.')]);
            }

            $event->setRelation('pool', $pool);
            $event = $this->synchronizeEventLifecycle->synchronize($event);
            $event->setRelation('pool', $pool);

            if (! $event->isPredictionOpen()) {
                $predictionsAreClosed = true;

                return null;
            }

            $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
            $validOptions = $event->options()->whereKey($optionIds)->get(['id', 'is_none']);
            if ($validOptions->count() !== count($optionIds)) {
                throw ValidationException::withMessages(['prediction' => __('One or more selected options are invalid.')]);
            }

            if (count($optionIds) > 1 && $validOptions->contains('is_none', true)) {
                throw ValidationException::withMessages([
                    'prediction' => __('The explicit none option cannot be combined with another answer.'),
                ]);
            }

            $minimum = $event->prediction_min_selections;
            $maximum = $event->prediction_max_selections;
            if (($submit && count($optionIds) < $minimum) || count($optionIds) > $maximum) {
                throw ValidationException::withMessages([
                    'prediction' => __('Select between :min and :max answers.', ['min' => $minimum, 'max' => $maximum]),
                ]);
            }

            $prediction = EventPrediction::query()->firstOrNew([
                'event_id' => $event->id,
                'pool_member_id' => $member->id,
            ]);
            $wasSubmitted = in_array($prediction->status, [PredictionStatus::Submitted, PredictionStatus::Locked], true);
            $remainsSubmitted = $submit || ($wasSubmitted && count($optionIds) >= $minimum);
            $prediction->status = $remainsSubmitted ? PredictionStatus::Submitted : PredictionStatus::Draft;
            $prediction->submitted_at = $remainsSubmitted ? ($prediction->submitted_at ?? now()) : null;
            $prediction->locked_at = null;
            $prediction->save();
            $prediction->options()->sync($optionIds);

            return $prediction->fresh('options');
        }, attempts: 3);

        if ($predictionsAreClosed || $prediction === null) {
            throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this event.')]);
        }

        return $prediction;
    }
}
