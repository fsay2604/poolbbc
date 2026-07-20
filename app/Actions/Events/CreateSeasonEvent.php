<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Http\Requests\Events\CreateSeasonEventRequest;
use App\Models\EventType;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateSeasonEvent
{
    public function __construct(
        private StandardEventTypeCatalog $catalog,
        private SynchronizeOfficialPoolEvents $synchronizeOfficialPoolEvents,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(SeasonRound $round, User $administrator, array $data): SeasonEvent
    {
        Gate::forUser($administrator)->authorize('create', SeasonEvent::class);
        $request = new CreateSeasonEventRequest;
        $validated = Validator::make(
            ['eventForm' => $data],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate()['eventForm'];

        return DB::transaction(function () use ($round, $administrator, $validated): SeasonEvent {
            $round = SeasonRound::query()->lockForUpdate()->findOrFail($round->id);
            Gate::forUser($administrator)->authorize('create', SeasonEvent::class);
            $eventType = EventType::query()
                ->whereKey($validated['event_type_id'])
                ->whereNull('pool_id')
                ->where('scope_key', 'global')
                ->where('is_standard', true)
                ->whereIn('slug', $this->catalog->slugs())
                ->lockForUpdate()
                ->first();

            if ($eventType === null) {
                throw ValidationException::withMessages([
                    'eventForm.event_type_id' => __('Select a managed standard event type.'),
                ]);
            }

            $event = $round->events()->create([
                ...$this->catalog->seasonEventAttributes($eventType),
                'event_type_id' => $eventType->id,
                'created_by' => $administrator->id,
                'name' => trim($validated['name']),
                'question' => filled($validated['question'] ?? null) ? trim($validated['question']) : null,
                'default_mode' => $validated['default_mode'],
                'status' => EventStatus::Draft,
                'position' => ((int) $round->events()->max('position')) + 1,
                'opens_at' => Carbon::parse($validated['opens_at'], config('app.timezone')),
                'locks_at' => Carbon::parse($validated['locks_at'], config('app.timezone')),
                'prediction_min_selections' => (int) $validated['prediction_min_selections'],
                'prediction_max_selections' => (int) $validated['prediction_max_selections'],
                'result_min_selections' => (int) $validated['result_min_selections'],
                'result_max_selections' => (int) $validated['result_max_selections'],
                'result_publication_mode' => $validated['result_publication_mode'],
                'include_inactive_houseguests' => (bool) $validated['include_inactive_houseguests'],
                'allow_none' => (bool) $validated['allow_none'],
            ]);

            $this->synchronizeOfficialPoolEvents->handleEvent($event);
            $this->recordAuditLog->handle(null, $administrator, 'season_event.created', $event, [
                'before' => null,
                'after' => $this->snapshot($event),
            ]);

            return $event->fresh(['eventType', 'poolEvents']);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(SeasonEvent $event): array
    {
        return [
            'season_round_id' => $event->season_round_id,
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'question' => $event->question,
            'default_mode' => $event->default_mode->value,
            'opens_at' => $event->opens_at?->toISOString(),
            'locks_at' => $event->locks_at?->toISOString(),
            'prediction_min_selections' => $event->prediction_min_selections,
            'prediction_max_selections' => $event->prediction_max_selections,
            'result_min_selections' => $event->result_min_selections,
            'result_max_selections' => $event->result_max_selections,
            'result_publication_mode' => $event->result_publication_mode->value,
            'include_inactive_houseguests' => $event->include_inactive_houseguests,
            'allow_none' => $event->allow_none,
        ];
    }
}
