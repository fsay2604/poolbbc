<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JoinPool
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(User $user, string $inviteCode): PoolMember
    {
        return DB::transaction(function () use ($user, $inviteCode): PoolMember {
            $pool = Pool::query()
                ->where('invite_code', mb_strtoupper(trim($inviteCode)))
                ->lockForUpdate()
                ->first();

            if ($pool === null) {
                throw ValidationException::withMessages(['joinForm.invite_code' => __('Invitation code is invalid.')]);
            }

            if ($pool->registrations_closed_at !== null || $pool->status !== PoolStatus::Registration) {
                throw ValidationException::withMessages(['joinForm.invite_code' => __('Registrations are closed for this pool.')]);
            }

            $existing = $pool->members()->whereBelongsTo($user)->first();
            if ($existing !== null) {
                if ($existing->status === PoolMemberStatus::Removed) {
                    throw ValidationException::withMessages([
                        'joinForm.invite_code' => __('You were removed from this pool. Contact its administrator.'),
                    ]);
                }

                return $existing;
            }

            if ($pool->activeMembers()->count() >= $pool->max_members) {
                throw ValidationException::withMessages(['joinForm.invite_code' => __('This pool is full.')]);
            }

            $member = $pool->members()->create([
                'user_id' => $user->id,
                'role' => PoolMemberRole::Member,
                'status' => PoolMemberStatus::Active,
                'draft_position' => $pool->usesDraft()
                    ? ((int) $pool->members()->max('draft_position')) + 1
                    : null,
                'joined_at' => now(),
            ]);

            $this->recordAuditLog->handle($pool, $user, 'pool.joined', $member);

            return $member;
        });
    }
}
