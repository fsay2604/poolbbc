<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Models\SeasonEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteSeasonEvent
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(SeasonEvent $event, User $administrator): void
    {
        Gate::forUser($administrator)->authorize('delete', $event);

        DB::transaction(function () use ($event, $administrator): void {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($administrator)->authorize('delete', $event);
            $this->assertCanDelete($event);
            $before = $this->snapshot($event);

            $this->recordAuditLog->handle(null, $administrator, 'season_event.deleted', $event, [
                'before' => $before,
                'after' => null,
            ]);
            $event->delete();
        }, attempts: 3);
    }

    /** @internal Shared with atomic round deletion. */
    public function assertCanDelete(SeasonEvent $event): void
    {
        $hasPredictions = $event->poolEvents()->whereHas('predictions')->exists();
        $hasPoints = $event->poolEvents()->whereHas('pointEntries')->exists();

        if ($event->status !== EventStatus::Draft
            || $event->effectiveStatus() !== EventStatus::Draft
            || $event->options_locked_at !== null
            || $hasPredictions
            || $hasPoints
            || $event->results()->exists()) {
            throw ValidationException::withMessages([
                'eventDeletion' => __('Only an unopened official event without responses, results, or points can be deleted. Cancel it instead.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SeasonEvent $event): array
    {
        return [
            'season_round_id' => $event->season_round_id,
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'status' => $event->status->value,
            'position' => $event->position,
            'opens_at' => $event->opens_at?->toISOString(),
            'locks_at' => $event->locks_at?->toISOString(),
        ];
    }
}
