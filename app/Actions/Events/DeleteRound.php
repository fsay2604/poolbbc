<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteRound
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, Round $round, User $manager): void
    {
        Gate::forUser($manager)->authorize('update', $pool);

        DB::transaction(function () use ($pool, $round, $manager): void {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            Gate::forUser($manager)->authorize('update', $pool);
            $round = $pool->rounds()->lockForUpdate()->findOrFail($round->id);
            $events = $round->events()->orderBy('id')->lockForUpdate()->get();
            $this->assertCanDelete($round, $events);
            $before = [
                ...$this->snapshot($round),
                'event_ids' => $events->modelKeys(),
            ];

            $this->recordAuditLog->handle($pool, $manager, 'round.deleted', $round, [
                'before' => $before,
                'after' => null,
            ]);
            $round->delete();
        }, attempts: 3);
    }

    /** @param Collection<int, Event> $events */
    private function assertCanDelete(Round $round, Collection $events): void
    {
        $eventIds = $events->modelKeys();
        $hasDependencies = $eventIds !== [] && (
            EventPrediction::query()->whereIn('event_id', $eventIds)->exists()
            || EventResult::query()->whereIn('event_id', $eventIds)->exists()
            || PointEntry::query()->whereIn('event_id', $eventIds)->exists()
            || PoolEvent::query()->whereIn('local_event_id', $eventIds)->exists()
        );

        if ($round->status !== 'draft'
            || $events->contains(fn (Event $event): bool => $event->status !== EventStatus::Draft
                || $event->effectiveStatus() !== EventStatus::Draft)
            || $hasDependencies) {
            throw ValidationException::withMessages([
                'roundDeletion' => __('Only a draft round whose events are unopened and unused can be deleted.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Round $round): array
    {
        return [
            'pool_id' => $round->pool_id,
            'name' => $round->name,
            'status' => $round->status,
            'position' => $round->position,
            'starts_at' => $round->starts_at?->toISOString(),
            'ends_at' => $round->ends_at?->toISOString(),
        ];
    }
}
