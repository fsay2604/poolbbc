<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteSeasonRound
{
    public function __construct(
        private DeleteSeasonEvent $deleteSeasonEvent,
        private RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(SeasonRound $round, User $administrator): void
    {
        Gate::forUser($administrator)->authorize('delete', $round);

        DB::transaction(function () use ($round, $administrator): void {
            $round = SeasonRound::query()->lockForUpdate()->findOrFail($round->id);
            Gate::forUser($administrator)->authorize('delete', $round);
            $events = SeasonEvent::query()
                ->where('season_round_id', $round->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($round->status !== 'draft') {
                throw ValidationException::withMessages([
                    'roundDeletion' => __('Only a draft official round can be deleted. Cancel its events instead.'),
                ]);
            }

            $events->each(fn (SeasonEvent $event) => $this->deleteSeasonEvent->assertCanDelete($event));
            $before = [
                'season_id' => $round->season_id,
                'name' => $round->name,
                'status' => $round->status,
                'position' => $round->position,
                'starts_at' => $round->starts_at?->toISOString(),
                'ends_at' => $round->ends_at?->toISOString(),
                'event_ids' => $events->pluck('id')->all(),
            ];

            $this->recordAuditLog->handle(null, $administrator, 'season_round.deleted', $round, [
                'before' => $before,
                'after' => null,
            ]);
            $round->delete();
        }, attempts: 3);
    }
}
