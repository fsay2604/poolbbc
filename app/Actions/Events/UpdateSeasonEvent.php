<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Http\Requests\Events\UpdateSeasonEventRequest;
use App\Models\SeasonEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateSeasonEvent
{
    public function __construct(
        private SynchronizeOfficialPoolEvents $synchronizeOfficialPoolEvents,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(SeasonEvent $event, User $administrator, array $data): SeasonEvent
    {
        Gate::forUser($administrator)->authorize('update', $event);
        $request = new UpdateSeasonEventRequest;
        $validated = Validator::make(
            ['eventForm' => $data],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate()['eventForm'];

        return DB::transaction(function () use ($event, $administrator, $validated): SeasonEvent {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($administrator)->authorize('update', $event);
            $this->assertCanUpdate($event);
            $before = $this->snapshot($event);

            $event->update([
                'name' => trim($validated['name']),
                'question' => filled($validated['question'] ?? null) ? trim($validated['question']) : null,
                'default_mode' => $validated['default_mode'],
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
            $this->recordAuditLog->handle(null, $administrator, 'season_event.updated', $event, [
                'before' => $before,
                'after' => $this->snapshot($event),
            ]);

            return $event->fresh(['eventType', 'poolEvents']);
        }, attempts: 3);
    }

    private function assertCanUpdate(SeasonEvent $event): void
    {
        $hasResponses = $event->poolEvents()->whereHas('predictions')->exists();
        $hasPoints = $event->poolEvents()->whereHas('pointEntries')->exists();

        if ($event->status !== EventStatus::Draft
            || $event->effectiveStatus() !== EventStatus::Draft
            || $event->options_locked_at !== null
            || $hasResponses
            || $hasPoints
            || $event->results()->exists()) {
            throw ValidationException::withMessages([
                'eventForm' => __('Official event rules cannot change after opening or the first response.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SeasonEvent $event): array
    {
        return [
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
