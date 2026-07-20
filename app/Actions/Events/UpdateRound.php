<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Http\Requests\Pools\CreateRoundRequest;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateRound
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param array<string, mixed> $data */
    public function handle(Pool $pool, Round $round, User $manager, array $data): Round
    {
        Gate::forUser($manager)->authorize('update', $pool);
        $request = new CreateRoundRequest;
        $validated = Validator::make(
            ['roundForm' => $data],
            $request->rules(),
        )->validate()['roundForm'];

        return DB::transaction(function () use ($pool, $round, $manager, $validated): Round {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            Gate::forUser($manager)->authorize('update', $pool);
            $round = $pool->rounds()->lockForUpdate()->findOrFail($round->id);
            $events = $round->events()->orderBy('id')->lockForUpdate()->get();
            $this->assertCanMutate($round, $events);
            $before = $this->snapshot($round);

            $round->update([
                'name' => trim($validated['name']),
                'starts_at' => filled($validated['starts_at'] ?? null)
                    ? Carbon::parse($validated['starts_at'], config('app.timezone'))
                    : null,
                'ends_at' => filled($validated['ends_at'] ?? null)
                    ? Carbon::parse($validated['ends_at'], config('app.timezone'))
                    : null,
            ]);

            $this->recordAuditLog->handle($pool, $manager, 'round.updated', $round, [
                'before' => $before,
                'after' => $this->snapshot($round),
            ]);

            return $round->fresh();
        }, attempts: 3);
    }

    /** @param Collection<int, Event> $events */
    private function assertCanMutate(Round $round, Collection $events): void
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
                'roundForm' => __('Only a draft round without active or dependent events can be changed.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Round $round): array
    {
        return [
            'name' => $round->name,
            'starts_at' => $round->starts_at?->toISOString(),
            'ends_at' => $round->ends_at?->toISOString(),
        ];
    }
}
