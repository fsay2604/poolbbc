<?php

namespace App\Actions\Leaderboards;

use App\Models\Pool;
use Illuminate\Support\Collection;

class BuildPoolLeaderboard
{
    /** @return Collection<int, array{rank:int,member:\App\Models\PoolMember,total_points:int,active_houseguests:int}> */
    public function handle(Pool $pool): Collection
    {
        $members = $pool->activeMembers()
            ->with([
                'user',
                'pointEntries' => fn ($query) => $query->with('event')->latest()->limit(5),
            ])
            ->withSum('pointEntries as total_points', 'points')
            ->withCount([
                'draftPicks as active_houseguests_count' => fn ($query) => $query->whereHas(
                    'houseguest',
                    fn ($houseguestQuery) => $houseguestQuery->where('is_active', true),
                ),
            ])
            ->get()
            ->sortByDesc(fn ($member) => (int) $member->total_points)
            ->values();

        $previousPoints = null;
        $rank = 0;

        return $members->map(function ($member, int $index) use (&$previousPoints, &$rank): array {
            $totalPoints = (int) $member->total_points;
            if ($previousPoints === null || $totalPoints !== $previousPoints) {
                $rank = $index + 1;
                $previousPoints = $totalPoints;
            }

            return [
                'rank' => $rank,
                'member' => $member,
                'total_points' => $totalPoints,
                'active_houseguests' => (int) $member->active_houseguests_count,
            ];
        });
    }
}
