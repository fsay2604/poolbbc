<?php

namespace App\Actions\Predictions;

use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\PoolMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitEventPrediction
{
    /** @param list<int> $optionIds */
    public function handle(Event $event, PoolMember $member, array $optionIds, bool $submit = true): EventPrediction
    {
        return DB::transaction(function () use ($event, $member, $optionIds, $submit): EventPrediction {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($member->pool_id !== $event->pool_id || ! $event->isPredictionOpen()) {
                throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this event.')]);
            }

            $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
            $validOptionIds = $event->options()->whereKey($optionIds)->pluck('id')->all();
            if (count($validOptionIds) !== count($optionIds)) {
                throw ValidationException::withMessages(['prediction' => __('One or more selected options are invalid.')]);
            }

            $minimum = $event->prediction_min_selections;
            $maximum = $event->prediction_max_selections;
            if (count($optionIds) < $minimum || count($optionIds) > $maximum) {
                throw ValidationException::withMessages([
                    'prediction' => __('Select between :min and :max answers.', ['min' => $minimum, 'max' => $maximum]),
                ]);
            }

            $prediction = EventPrediction::query()->updateOrCreate(
                ['event_id' => $event->id, 'pool_member_id' => $member->id],
                [
                    'status' => $submit ? PredictionStatus::Submitted : PredictionStatus::Draft,
                    'submitted_at' => $submit ? now() : null,
                ],
            );
            $prediction->options()->sync($optionIds);

            return $prediction->fresh('options');
        });
    }
}
