<?php

namespace App\Actions\Drafts;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Enums\PoolStatus;
use App\Models\Draft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartDraft
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Draft $draft, User $administrator): Draft
    {
        return DB::transaction(function () use ($draft, $administrator): Draft {
            $draft = Draft::query()->lockForUpdate()->findOrFail($draft->id);
            $pool = $draft->pool()->lockForUpdate()->firstOrFail();

            if (! $pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot start this draft.'));
            }

            if ($pool->status !== PoolStatus::Registration) {
                throw ValidationException::withMessages(['draft' => __('Open registrations before starting the draft.')]);
            }

            if ($draft->status !== DraftStatus::Pending) {
                throw ValidationException::withMessages(['draft' => __('This draft has already started.')]);
            }

            $members = $pool->activeMembers()->orderBy('draft_position')->get();
            if ($members->isEmpty()) {
                throw ValidationException::withMessages(['draft' => __('At least one active member is required.')]);
            }

            $requiredHouseguests = $members->count() * $pool->picks_per_member;
            $availableHouseguests = $pool->season->houseguests()->where('is_active', true)->count();

            if ($pool->picks_per_member > $availableHouseguests
                || ($pool->exclusive_draft && $requiredHouseguests > $availableHouseguests)) {
                throw ValidationException::withMessages([
                    'draft' => __('There are not enough available houseguests for every roster.'),
                ]);
            }

            $before = [
                'draft' => ['status' => $draft->status->value],
                'pool' => [
                    'status' => $pool->status->value,
                    'registrations_closed_at' => $pool->registrations_closed_at?->toISOString(),
                ],
            ];
            $draft->update([
                'status' => DraftStatus::Active,
                'current_pick_number' => 1,
                'current_pool_member_id' => $members->first()->id,
                'turn_started_at' => now(),
                'started_at' => now(),
            ]);

            $pool->update([
                'status' => PoolStatus::Draft,
                'registrations_closed_at' => now(),
            ]);

            $this->recordAuditLog->handle($pool, $administrator, 'draft.started', $draft, [
                'member_count' => $members->count(),
                'picks_per_member' => $pool->picks_per_member,
                'before' => $before,
                'after' => [
                    'draft' => ['status' => $draft->status->value],
                    'pool' => [
                        'status' => $pool->status->value,
                        'registrations_closed_at' => $pool->registrations_closed_at?->toISOString(),
                    ],
                ],
            ]);

            return $draft->fresh(['currentMember.user', 'pool']);
        });
    }
}
