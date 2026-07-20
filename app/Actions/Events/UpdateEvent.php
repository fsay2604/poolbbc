<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Http\Requests\Events\CreateEventRequest;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\EventType;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateEvent
{
    public function __construct(
        private BuildEventOptions $buildEventOptions,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Pool $pool, Event $event, User $manager, array $data): Event
    {
        Gate::forUser($manager)->authorize('update', $pool);
        $data = $this->validate($data);

        return DB::transaction(function () use ($pool, $event, $manager, $data): Event {
            $pool = Pool::query()->with('season')->lockForUpdate()->findOrFail($pool->id);
            Gate::forUser($manager)->authorize('update', $pool);
            $event = $pool->events()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($manager)->authorize('update', $event);
            $round = $pool->rounds()->lockForUpdate()->findOrFail($data['round_id']);
            $options = $event->options()->lockForUpdate()->get();
            $event->setRelation('options', $options);
            $this->assertCanUpdate($event);
            $eventTypeId = $this->resolveEventTypeId($pool, $data['event_type_id']);
            $eventMode = EventMode::from($data['mode']);

            if (! $pool->supportsEventMode($eventMode)) {
                throw ValidationException::withMessages([
                    'eventForm.mode' => __('This scoring mode is not available for the pool competition mode.'),
                ]);
            }

            if (filled($data['opens_at'] ?? null)
                && Carbon::parse($data['locks_at'])->lessThanOrEqualTo(Carbon::parse($data['opens_at']))) {
                throw ValidationException::withMessages([
                    'eventForm.locks_at' => __('The lock time must be after the opening time.'),
                ]);
            }

            $before = $this->snapshot($event);
            $position = $event->round_id === $round->id
                ? $event->position
                : ((int) $round->events()->max('position')) + 1;

            $event->update([
                'round_id' => $round->id,
                'event_type_id' => $eventTypeId,
                'name' => trim($data['name']),
                'question' => filled($data['question'] ?? null) ? trim($data['question']) : null,
                'mode' => $eventMode,
                'answer_source' => $data['answer_source'],
                'position' => $position,
                'opens_at' => filled($data['opens_at'] ?? null)
                    ? Carbon::parse($data['opens_at'], config('app.timezone'))
                    : null,
                'locks_at' => Carbon::parse($data['locks_at'], config('app.timezone')),
                'prediction_min_selections' => (int) $data['prediction_min_selections'],
                'prediction_max_selections' => (int) $data['prediction_max_selections'],
                'result_min_selections' => (int) $data['result_min_selections'],
                'result_max_selections' => (int) $data['result_max_selections'],
                'scoring_config' => $data['scoring_config'],
                'result_publication_mode' => $data['result_publication_mode'],
            ]);

            $event->options()->delete();
            $this->buildEventOptions->handle($event, $pool, $data);
            $event->load('options');

            $this->recordAuditLog->handle($pool, $manager, 'event.updated', $event, [
                'before' => $before,
                'after' => $this->snapshot($event),
            ]);

            return $event->fresh(['options', 'round', 'eventType']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        if (! is_array($data['scoring_config'] ?? null)) {
            throw ValidationException::withMessages([
                'eventForm.scoring_config' => __('The scoring configuration is invalid.'),
            ]);
        }

        $request = new CreateEventRequest;
        $validationData = [
            ...$data,
            'owner_points' => data_get($data, 'scoring_config.owner.points_per_match'),
            'prediction_points' => data_get($data, 'scoring_config.prediction.points_per_correct'),
            'exact_bonus' => data_get($data, 'scoring_config.prediction.exact_match_bonus'),
            'wrong_penalty' => data_get($data, 'scoring_config.prediction.wrong_answer_penalty'),
            'allow_negative' => data_get($data, 'scoring_config.allow_negative'),
            'save_as_template' => false,
        ];
        $validated = Validator::make(
            ['eventForm' => $validationData],
            $request->rules(),
        )->validate()['eventForm'];

        return [
            ...$validated,
            'scoring_config' => [
                'owner' => ['points_per_match' => (int) $validated['owner_points']],
                'prediction' => [
                    'points_per_correct' => (int) $validated['prediction_points'],
                    'exact_match_bonus' => (int) $validated['exact_bonus'],
                    'wrong_answer_penalty' => (int) $validated['wrong_penalty'],
                ],
                'allow_negative' => (bool) $validated['allow_negative'],
            ],
            'custom_options' => $data['custom_options'] ?? [],
        ];
    }

    private function assertCanUpdate(Event $event): void
    {
        if ($event->status !== EventStatus::Draft
            || $event->effectiveStatus() !== EventStatus::Draft
            || EventPrediction::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || EventResult::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || PointEntry::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || PoolEvent::query()->whereBelongsTo($event, 'localEvent')->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages([
                'eventForm' => __('Event rules cannot change after opening or the first dependent record.'),
            ]);
        }
    }

    private function resolveEventTypeId(Pool $pool, mixed $eventTypeId): ?int
    {
        if (blank($eventTypeId)) {
            return null;
        }

        return EventType::query()
            ->whereKey($eventTypeId)
            ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $pool->id))
            ->firstOrFail()
            ->id;
    }

    /** @return array<string, mixed> */
    private function snapshot(Event $event): array
    {
        return [
            'round_id' => $event->round_id,
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'question' => $event->question,
            'mode' => $event->mode->value,
            'answer_source' => $event->answer_source->value,
            'position' => $event->position,
            'opens_at' => $event->opens_at?->toISOString(),
            'locks_at' => $event->locks_at?->toISOString(),
            'prediction_min_selections' => $event->prediction_min_selections,
            'prediction_max_selections' => $event->prediction_max_selections,
            'result_min_selections' => $event->result_min_selections,
            'result_max_selections' => $event->result_max_selections,
            'scoring_config' => $event->scoring_config,
            'result_publication_mode' => $event->result_publication_mode->value,
            'options' => $event->options->map(fn ($option): array => [
                'houseguest_id' => $option->houseguest_id,
                'label' => $option->label,
                'value' => $option->value,
                'position' => $option->position,
                'is_none' => $option->is_none,
            ])->all(),
        ];
    }
}
