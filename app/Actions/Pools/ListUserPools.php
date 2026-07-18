<?php

namespace App\Actions\Pools;

use App\Enums\PoolMemberStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Collection;

class ListUserPools
{
    /** @return Collection<int, Pool> */
    public function handle(User $user): Collection
    {
        return Pool::query()
            ->whereHas('members', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', PoolMemberStatus::Active->value))
            ->with('season')
            ->orderBy('name')
            ->get();
    }
}
