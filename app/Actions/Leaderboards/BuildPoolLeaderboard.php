<?php

namespace App\Actions\Leaderboards;

use App\Models\Pool;
use App\Models\Round;
use App\Models\SeasonRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BuildPoolLeaderboard
{
    /**
     * @param  array{round?:string,event_type_id?:int|null}  $filters
     * @return Collection<int, array{rank:int,rank_change:?int,member:\App\Models\PoolMember,total_points:int,active_houseguests:int}>
     */
    public function handle(Pool $pool, array $filters = []): Collection
    {
        if (($filters['round'] ?? '') !== '' || ($filters['event_type_id'] ?? null) !== null) {
            return $this->build($pool, $filters);
        }

        return Cache::remember(
            self::cacheKey($pool->id),
            now()->addMinutes(10),
            fn (): Collection => $this->build($pool, $filters),
        );
    }

    public static function cacheKey(int $poolId): string
    {
        return "pool.leaderboard.{$poolId}";
    }

    /** @param array{round?:string,event_type_id?:int|null} $filters */
    private function build(Pool $pool, array $filters): Collection
    {
        $round = $filters['round'] ?? '';
        $eventTypeId = $filters['event_type_id'] ?? null;
        $hasActiveFilters = $round !== '' || $eventTypeId !== null;
        $latestRoundPosition = $hasActiveFilters ? null : $this->latestRoundPosition($pool);

        $members = $pool->competitionMembers()
            ->with([
                'user',
                'pointEntries' => fn ($relation) => $this->applyFilters($relation->getQuery(), $round, $eventTypeId)
                    ->with(['event.round', 'event.eventType', 'poolEvent.seasonEvent.round', 'poolEvent.seasonEvent.eventType'])
                    ->latest(),
            ])
            ->withSum([
                'pointEntries as total_points' => fn (Builder $query) => $this->applyFilters($query, $round, $eventTypeId),
            ], 'points')
            ->when($latestRoundPosition !== null, fn (Builder $query) => $query->withSum([
                'pointEntries as previous_total_points' => fn (Builder $pointQuery) => $pointQuery
                    ->published()
                    ->whereDoesntHave('event.round', fn (Builder $roundQuery) => $roundQuery->where('position', $latestRoundPosition))
                    ->whereDoesntHave('poolEvent.localEvent.round', fn (Builder $roundQuery) => $roundQuery->where('position', $latestRoundPosition))
                    ->whereDoesntHave('poolEvent.seasonEvent.round', fn (Builder $roundQuery) => $roundQuery->where('position', $latestRoundPosition)),
            ], 'points'))
            ->withCount([
                'draftPicks as active_houseguests_count' => fn (Builder $query) => $query->whereHas(
                    'houseguest',
                    fn (Builder $houseguestQuery) => $houseguestQuery->where('is_active', true),
                ),
            ])
            ->get()
            ->sortByDesc(fn ($member) => (int) $member->total_points)
            ->values();

        $currentRanks = $this->ranksFor($members, 'total_points');
        $previousRanks = $latestRoundPosition === null
            ? collect()
            : $this->ranksFor($members->sortByDesc(fn ($member) => (int) $member->previous_total_points)->values(), 'previous_total_points');

        return $members->map(function ($member) use ($currentRanks, $hasActiveFilters, $previousRanks): array {
            $currentRank = $currentRanks[$member->id];
            $previousRank = $previousRanks[$member->id] ?? $currentRank;

            return [
                'rank' => $currentRank,
                'rank_change' => $hasActiveFilters ? null : $previousRank - $currentRank,
                'member' => $member,
                'total_points' => (int) $member->total_points,
                'active_houseguests' => (int) $member->active_houseguests_count,
            ];
        });
    }

    private function applyFilters(Builder $query, string $round, ?int $eventTypeId): Builder
    {
        $query->published();

        if ($round !== '') {
            [$source, $roundId] = array_pad(explode(':', $round, 2), 2, null);
            $roundId = (int) $roundId;

            if ($source === 'official') {
                $query->whereHas('poolEvent.seasonEvent', fn (Builder $eventQuery) => $eventQuery->where('season_round_id', $roundId));
            } elseif ($source === 'local') {
                $query->whereHas('event', fn (Builder $eventQuery) => $eventQuery->where('round_id', $roundId));
            }
        }

        if ($eventTypeId !== null) {
            $query->where(function (Builder $typeQuery) use ($eventTypeId): void {
                $typeQuery
                    ->whereHas('event', fn (Builder $eventQuery) => $eventQuery->where('event_type_id', $eventTypeId))
                    ->orWhereHas('poolEvent.seasonEvent', fn (Builder $eventQuery) => $eventQuery->where('event_type_id', $eventTypeId));
            });
        }

        return $query;
    }

    /** @return Collection<int, int> */
    private function ranksFor(Collection $members, string $attribute): Collection
    {
        $previousPoints = null;
        $rank = 0;

        return $members->mapWithKeys(function ($member, int $index) use ($attribute, &$previousPoints, &$rank): array {
            $points = (int) $member->{$attribute};
            if ($previousPoints === null || $points !== $previousPoints) {
                $rank = $index + 1;
                $previousPoints = $points;
            }

            return [$member->id => $rank];
        });
    }

    private function latestRoundPosition(Pool $pool): ?int
    {
        $officialRoundPosition = SeasonRound::query()
            ->where('season_id', $pool->season_id)
            ->whereHas('events.poolEvents', fn (Builder $poolEventQuery) => $poolEventQuery
                ->where('pool_id', $pool->id)
                ->whereHas('pointEntries', fn (Builder $pointQuery) => $pointQuery->published()))
            ->max('position');

        $localRoundPosition = Round::query()
            ->whereBelongsTo($pool)
            ->whereHas('events.results.pointEntries', fn (Builder $pointQuery) => $pointQuery->published())
            ->max('position');

        if ($officialRoundPosition === null && $localRoundPosition === null) {
            return null;
        }

        return max((int) $officialRoundPosition, (int) $localRoundPosition);
    }
}
