<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\SynchronizeOfficialPoolEvents;
use App\Enums\DraftStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\Draft;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePool
{
    public function __construct(
        private RecordAuditLog $recordAuditLog,
        private SynchronizeOfficialPoolEvents $synchronizeOfficialPoolEvents,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $owner, array $data): Pool
    {
        return DB::transaction(function () use ($owner, $data): Pool {
            $usesScoringOverrides = array_key_exists('use_scoring_overrides', $data)
                ? (bool) Arr::pull($data, 'use_scoring_overrides')
                : null;
            if ($usesScoringOverrides === false) {
                $data['scoring_config'] = [];
            } else {
                $data['scoring_config'] ??= [];
            }
            $pool = Pool::query()->create([
                ...$data,
                'owner_id' => $owner->id,
                'invite_code' => $this->uniqueInviteCode(),
                'status' => PoolStatus::Configuration,
            ]);

            $member = PoolMember::query()->create([
                'pool_id' => $pool->id,
                'user_id' => $owner->id,
                'role' => PoolMemberRole::Owner,
                'status' => PoolMemberStatus::Active,
                'draft_position' => $pool->usesDraft() ? 1 : null,
                'joined_at' => now(),
            ]);

            if ($pool->usesDraft()) {
                Draft::query()->create([
                    'pool_id' => $pool->id,
                    'status' => DraftStatus::Pending,
                    'current_pool_member_id' => $member->id,
                ]);
            }

            $this->synchronizeOfficialPoolEvents->handlePool($pool);

            $this->recordAuditLog->handle($pool, $owner, 'pool.created', $pool, [
                'season_id' => $pool->season_id,
                'draft_mode' => $pool->draft_mode->value,
                'competition_mode' => $pool->competition_mode->value,
            ]);

            return $pool->load('season', 'draft', 'members.user');
        });
    }

    private function uniqueInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Pool::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
