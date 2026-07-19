<?php

namespace App\Actions\Dashboard;

use App\Enums\PoolMemberStatus;
use App\Models\Pool;
use App\Models\User;

class BuildDashboardStats
{
    /**
     * @return array{pools: \Illuminate\Support\Collection<int, Pool>}
     */
    public function handle(?User $user = null): array
    {
        if ($user === null) {
            return ['pools' => collect()];
        }

        $pools = Pool::query()
            ->whereHas('members', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', PoolMemberStatus::Active->value))
            ->with([
                'season',
                'activeMembers' => fn ($query) => $query
                    ->whereBelongsTo($user)
                    ->withSum([
                        'pointEntries' => fn ($pointQuery) => $pointQuery->published(),
                    ], 'points'),
            ])
            ->withCount('activeMembers')
            ->orderBy('name')
            ->get();

        return ['pools' => $pools];
    }
}
