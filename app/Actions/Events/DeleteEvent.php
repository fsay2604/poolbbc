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
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteEvent
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, Event $event, User $manager): void
    {
        Gate::forUser($manager)->authorize('update', $pool);

        DB::transaction(function () use ($pool, $event, $manager): void {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            Gate::forUser($manager)->authorize('update', $pool);
            $event = $pool->events()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($manager)->authorize('update', $event);
            $options = $event->options()->lockForUpdate()->get();
            $event->setRelation('options', $options);
            $this->assertCanDelete($event);
            Gate::forUser($manager)->authorize('delete', $event);
            $before = $this->snapshot($event);

            $this->recordAuditLog->handle($pool, $manager, 'event.deleted', $event, [
                'before' => $before,
                'after' => null,
            ]);
            $event->delete();
        }, attempts: 3);
    }

    /** @internal Shared with policy-aligned mutation flows. */
    public function assertCanDelete(Event $event): void
    {
        if ($event->status !== EventStatus::Draft
            || $event->effectiveStatus() !== EventStatus::Draft
            || EventPrediction::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || EventResult::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || PointEntry::query()->whereBelongsTo($event)->lockForUpdate()->first() !== null
            || PoolEvent::query()->whereBelongsTo($event, 'localEvent')->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages([
                'eventDeletion' => __('Only an unopened local event without responses, results, points, or projections can be deleted.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Event $event): array
    {
        return [
            'pool_id' => $event->pool_id,
            'round_id' => $event->round_id,
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'status' => $event->status->value,
            'mode' => $event->mode->value,
            'answer_source' => $event->answer_source->value,
            'position' => $event->position,
            'opens_at' => $event->opens_at?->toISOString(),
            'locks_at' => $event->locks_at?->toISOString(),
            'option_ids' => $event->options->modelKeys(),
        ];
    }
}
