<?php

namespace App\Actions\Drafts;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Enums\PoolStatus;
use App\Models\Draft;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetDraftOrder
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param list<int> $memberIds */
    public function handle(Draft $draft, User $administrator, array $memberIds): Draft
    {
        return DB::transaction(function () use ($draft, $administrator, $memberIds): Draft {
            $draft = Draft::query()->lockForUpdate()->findOrFail($draft->id);
            $pool = $draft->pool()->lockForUpdate()->firstOrFail();

            if (! $pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot set this draft order.'));
            }

            if ($draft->status !== DraftStatus::Pending || $pool->status !== PoolStatus::Registration) {
                throw ValidationException::withMessages([
                    'draft' => __('The draft order is locked after the draft has started.'),
                ]);
            }

            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
            $activeMemberIds = $pool->activeMembers()
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $submittedMemberIds = collect($memberIds)->sort()->values()->all();

            if ($memberIds === [] || $submittedMemberIds !== $activeMemberIds) {
                throw ValidationException::withMessages([
                    'draft' => __('The draft order must contain every active member exactly once.'),
                ]);
            }

            $temporaryOffset = count($memberIds);
            collect($memberIds)->each(function (int $memberId, int $index) use ($temporaryOffset): void {
                PoolMember::query()->whereKey($memberId)->update(['draft_position' => $temporaryOffset + $index + 1]);
            });
            collect($memberIds)->each(function (int $memberId, int $index): void {
                PoolMember::query()->whereKey($memberId)->update(['draft_position' => $index + 1]);
            });
            $draft->update(['current_pool_member_id' => $memberIds[0]]);

            $this->recordAuditLog->handle($pool, $administrator, 'draft.order_confirmed', $draft, [
                'pool_member_ids' => $memberIds,
            ]);

            return $draft->fresh(['currentMember.user', 'pool.members.user']);
        }, attempts: 3);
    }
}
