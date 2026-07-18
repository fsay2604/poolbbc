<?php

namespace App\Actions\Leaderboards;

use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolScoreProjection;
use Illuminate\Support\Facades\DB;

class RebuildPoolScoreProjection
{
    public function handle(Pool $pool): void
    {
        DB::transaction(function () use ($pool): void {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            $members = $pool->competitionMembers()->pluck('id');
            $totals = PointEntry::query()
                ->published()
                ->selectRaw('pool_member_id, SUM(points) as total_points, MAX(id) as last_point_entry_id')
                ->whereIn('pool_member_id', $members)
                ->groupBy('pool_member_id')
                ->get()
                ->keyBy('pool_member_id');

            foreach ($members as $memberId) {
                $total = $totals->get($memberId);
                PoolScoreProjection::query()->updateOrCreate(
                    ['pool_id' => $pool->id, 'pool_member_id' => $memberId],
                    [
                        'total_points' => (int) ($total?->total_points ?? 0),
                        'last_point_entry_id' => $total?->last_point_entry_id,
                        'rebuilt_at' => now(),
                    ],
                );
            }

            PoolScoreProjection::query()
                ->where('pool_id', $pool->id)
                ->whereNotIn('pool_member_id', $members)
                ->delete();
        }, attempts: 3);
    }
}
