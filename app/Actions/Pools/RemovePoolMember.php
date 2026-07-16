<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemovePoolMember
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, PoolMember $member, User $administrator): void
    {
        DB::transaction(function () use ($pool, $member, $administrator): void {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            $member = $pool->members()->lockForUpdate()->findOrFail($member->id);

            if ($member->role === PoolMemberRole::Owner) {
                throw ValidationException::withMessages(['member' => __('The pool owner cannot be removed.')]);
            }

            $draftPending = $pool->draft()->where('status', DraftStatus::Pending->value)->exists();
            $member->update([
                'status' => PoolMemberStatus::Removed,
                'removed_at' => now(),
                'draft_position' => $draftPending ? null : $member->draft_position,
            ]);

            if ($draftPending) {
                $pool->activeMembers()->orderBy('draft_position')->get()
                    ->each(fn (PoolMember $activeMember, int $index) => $activeMember->update(['draft_position' => $index + 1]));
            }

            $this->recordAuditLog->handle($pool, $administrator, 'pool.member_removed', $member, [
                'removed_user_id' => $member->user_id,
            ]);
        });
    }
}
