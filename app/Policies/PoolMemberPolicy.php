<?php

namespace App\Policies;

use App\Enums\PoolMemberRole;
use App\Models\PoolMember;
use App\Models\User;

class PoolMemberPolicy
{
    public function remove(User $user, PoolMember $member): bool
    {
        return $member->role !== PoolMemberRole::Owner
            && $member->pool->isManagedBy($user);
    }

    public function leave(User $user, PoolMember $member): bool
    {
        return $member->role !== PoolMemberRole::Owner
            && $member->user_id === $user->id;
    }
}
