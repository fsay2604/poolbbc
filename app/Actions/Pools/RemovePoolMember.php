<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Enums\DraftStatus;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemovePoolMember
{
    public function __construct(
        private RecordAuditLog $recordAuditLog,
        private RebuildPoolScoreProjection $rebuildPoolScoreProjection,
    ) {}

    public function handle(Pool $pool, PoolMember $member, User $actor, string $reason): void
    {
        $reason = trim($reason);

        DB::transaction(function () use ($pool, $member, $actor, $reason): void {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            $member = $pool->members()->lockForUpdate()->findOrFail($member->id);

            $departure = $member->user_id === $actor->id ? 'leave' : 'remove';
            Gate::forUser($actor)->authorize($departure, $member);

            if ($member->status === PoolMemberStatus::Removed) {
                return;
            }

            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages([
                    'reason' => __('A departure reason between 3 and 1000 characters is required.'),
                ]);
            }

            if (in_array($pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)) {
                throw ValidationException::withMessages([
                    'member' => __('Members cannot be changed after the pool is completed.'),
                ]);
            }

            $draftStatus = $pool->draft()->first()?->status;
            if (in_array($draftStatus, [DraftStatus::Active, DraftStatus::Paused], true)) {
                throw ValidationException::withMessages([
                    'member' => __('A member cannot leave while the draft is active or paused.'),
                ]);
            }

            $draftPending = $draftStatus === DraftStatus::Pending;
            $teamPreserved = $member->draftPicks()->exists();
            $before = [
                'status' => $member->status->value,
                'draft_position' => $member->draft_position,
                'removed_at' => $member->removed_at?->toISOString(),
            ];
            $member->update([
                'status' => PoolMemberStatus::Removed,
                'removed_at' => now(),
                'draft_position' => $draftPending ? null : $member->draft_position,
            ]);

            if ($draftPending) {
                $pool->activeMembers()->orderBy('draft_position')->get()
                    ->each(fn (PoolMember $activeMember, int $index) => $activeMember->update(['draft_position' => $index + 1]));
            }

            $this->recordAuditLog->handle($pool, $actor, $departure === 'leave' ? 'pool.member_left' : 'pool.member_removed', $member, [
                'removed_user_id' => $member->user_id,
                'departure' => $departure,
                'reason' => $reason,
                'team_preserved' => $teamPreserved,
                'before' => $before,
                'after' => [
                    'status' => $member->status->value,
                    'draft_position' => $member->draft_position,
                    'removed_at' => $member->removed_at?->toISOString(),
                ],
            ]);
        }, attempts: 3);

        Cache::forget(BuildPoolLeaderboard::cacheKey($pool->id));
        $this->rebuildPoolScoreProjection->handle($pool);
    }
}
