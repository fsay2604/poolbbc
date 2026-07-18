<?php

namespace App\Actions\Pools;

use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PreviewPoolInvitation
{
    public function handle(User $user, string $inviteCode): Pool
    {
        $pool = Pool::query()
            ->where('invite_code', mb_strtoupper(trim($inviteCode)))
            ->with('season')
            ->withCount('activeMembers')
            ->first();

        if ($pool === null) {
            throw ValidationException::withMessages([
                'joinForm.invite_code' => __('Invitation code is invalid.'),
            ]);
        }

        if ($pool->registrations_closed_at !== null || $pool->status !== PoolStatus::Registration) {
            throw ValidationException::withMessages([
                'joinForm.invite_code' => __('Registrations are closed for this pool.'),
            ]);
        }

        $membershipStatus = $pool->members()
            ->whereBelongsTo($user)
            ->value('status');
        if (in_array($membershipStatus, [PoolMemberStatus::Removed, PoolMemberStatus::Removed->value], true)) {
            throw ValidationException::withMessages([
                'joinForm.invite_code' => __('You were removed from this pool. Contact its administrator.'),
            ]);
        }

        $alreadyActive = $pool->members()
            ->whereBelongsTo($user)
            ->where('status', PoolMemberStatus::Active->value)
            ->exists();
        if (! $alreadyActive && $pool->active_members_count >= $pool->max_members) {
            throw ValidationException::withMessages([
                'joinForm.invite_code' => __('This pool is full.'),
            ]);
        }

        return $pool;
    }
}
