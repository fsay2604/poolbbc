<?php

namespace App\Actions\Events;

use App\Enums\EventMode;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\SeasonEvent;
use Illuminate\Support\Facades\DB;

class SynchronizeOfficialPoolEvents
{
    public function handlePool(Pool $pool): void
    {
        SeasonEvent::query()
            ->with('eventType')
            ->whereHas('round', fn ($query) => $query->where('season_id', $pool->season_id))
            ->whereIn('status', ['draft', 'open'])
            ->where(fn ($query) => $query->whereNull('locks_at')->orWhere('locks_at', '>', now()))
            ->each(fn (SeasonEvent $event) => $this->attach($pool, $event));
    }

    public function handleEvent(SeasonEvent $event): void
    {
        $event->loadMissing(['round', 'eventType']);
        if (! in_array($event->status->value, ['draft', 'open'], true)
            || ($event->locks_at !== null && $event->locks_at->lessThanOrEqualTo(now()))) {
            return;
        }

        Pool::query()
            ->where('season_id', $event->round->season_id)
            ->whereNotIn('status', [PoolStatus::Completed->value, PoolStatus::Archived->value])
            ->each(fn (Pool $pool) => $this->attach($pool, $event));
    }

    private function attach(Pool $pool, SeasonEvent $event): ?PoolEvent
    {
        return DB::transaction(function () use ($pool, $event): ?PoolEvent {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            $event = SeasonEvent::query()->with(['round', 'eventType'])->lockForUpdate()->findOrFail($event->id);

            if ($event->round->season_id !== $pool->season_id
                || ! in_array($event->status->value, ['draft', 'open'], true)
                || ($event->locks_at !== null && $event->locks_at->lessThanOrEqualTo(now()))) {
                return null;
            }

            $mode = $this->compatibleMode($pool, $event);
            $scoringConfig = $event->scoring_config ?: [
                'owner' => ['points_per_match' => 1],
                'prediction' => [
                    'points_per_correct' => 1,
                    'exact_match_bonus' => 0,
                    'wrong_answer_penalty' => 0,
                ],
                'allow_negative' => false,
            ];
            $poolScoringConfig = is_array($pool->scoring_config) ? $pool->scoring_config : [];
            $predictionOverrides = data_get($poolScoringConfig, 'prediction', []);
            if (is_array($predictionOverrides)) {
                $scoringConfig['prediction'] = array_replace(
                    $scoringConfig['prediction'] ?? [],
                    $predictionOverrides,
                );
            }
            $eventTypeOverride = $event->eventType === null
                ? []
                : data_get($poolScoringConfig, 'event_types.'.$event->eventType->slug, []);
            if (array_key_exists('owner_points', $eventTypeOverride)) {
                $scoringConfig['owner']['points_per_match'] = (int) $eventTypeOverride['owner_points'];
            }
            if (array_key_exists('allow_negative', $poolScoringConfig)) {
                $scoringConfig['allow_negative'] = (bool) $poolScoringConfig['allow_negative'];
            }

            $attributes = [
                'local_event_id' => null,
                'mode' => $mode,
                'is_active' => true,
                'visibility' => 'after_lock',
                'prediction_min_selections' => $event->prediction_min_selections,
                'prediction_max_selections' => $event->prediction_max_selections,
                'scoring_config' => $scoringConfig,
            ];
            $poolEvent = PoolEvent::query()
                ->where('pool_id', $pool->id)
                ->where('season_event_id', $event->id)
                ->lockForUpdate()
                ->first();

            if ($poolEvent === null) {
                return PoolEvent::query()->create([
                    'pool_id' => $pool->id,
                    'season_event_id' => $event->id,
                    ...$attributes,
                ]);
            }

            if ($poolEvent->rules_customized_at === null) {
                $poolEvent->fill($attributes);

                if ($poolEvent->isDirty()) {
                    $poolEvent->save();
                }
            }

            return $poolEvent;
        }, attempts: 3);
    }

    private function compatibleMode(Pool $pool, SeasonEvent $event): EventMode
    {
        $preferredMode = $event->default_mode;

        if ($pool->supportsEventMode($preferredMode)) {
            return $preferredMode;
        }

        return $pool->usesPredictions() ? EventMode::Prediction : EventMode::Roster;
    }
}
