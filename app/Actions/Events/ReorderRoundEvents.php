<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\PoolStatus;
use App\Models\Event;
use App\Models\Round;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderRoundEvents
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param list<int> $eventIds */
    public function handle(Round $round, User $administrator, array $eventIds): Round
    {
        return DB::transaction(function () use ($round, $administrator, $eventIds): Round {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            $pool = $round->pool()->lockForUpdate()->firstOrFail();

            if (! $pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot reorder events in this pool.'));
            }

            if (in_array($pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)) {
                throw ValidationException::withMessages([
                    'events' => __('Events cannot be reordered after the pool is completed.'),
                ]);
            }

            $events = $round->events()
                ->withCount(['predictions', 'results'])
                ->orderBy('position')
                ->lockForUpdate()
                ->get();
            $currentEventIds = $events->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $eventIds = array_values(array_map('intval', $eventIds));
            $uniqueEventIds = array_values(array_unique($eventIds));

            if (count($eventIds) !== count($uniqueEventIds)
                || collect($uniqueEventIds)->sort()->values()->all() !== collect($currentEventIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'events' => __('The event order must contain every event from this round exactly once.'),
                ]);
            }

            if ($events->contains(fn (Event $event): bool => $event->effectiveStatus() !== EventStatus::Draft
                || $event->predictions_count > 0
                || $event->results_count > 0)) {
                throw ValidationException::withMessages([
                    'events' => __('Events can only be reordered while the entire round is in draft and has no responses or results.'),
                ]);
            }

            if ($eventIds === $currentEventIds) {
                return $round->load('events');
            }

            $temporaryOffset = max((int) $events->max('position'), count($eventIds));
            if ($temporaryOffset + count($eventIds) > 65535) {
                throw ValidationException::withMessages([
                    'events' => __('The event positions cannot be reordered safely.'),
                ]);
            }

            collect($eventIds)->each(function (int $eventId, int $index) use ($round, $temporaryOffset): void {
                $round->events()->whereKey($eventId)->update(['position' => $temporaryOffset + $index + 1]);
            });
            collect($eventIds)->each(function (int $eventId, int $index) use ($round): void {
                $round->events()->whereKey($eventId)->update(['position' => $index + 1]);
            });

            $this->recordAuditLog->handle($pool, $administrator, 'round.events_reordered', $round, [
                'previous_event_ids' => $currentEventIds,
                'event_ids' => $eventIds,
            ]);

            return $round->fresh('events');
        }, attempts: 3);
    }
}
