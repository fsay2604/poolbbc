<?php

namespace App\Actions\Houseguests;

use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

class RebuildSeasonHouseguestActivity
{
    public function handle(Season $season): void
    {
        DB::transaction(function () use ($season): void {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);
            $this->rebuild($season);
        }, attempts: 3);
    }

    private function rebuild(Season $season): void
    {
        Houseguest::query()->whereBelongsTo($season)->update(['is_active' => true]);

        $evictedIds = $season->canonicalRounds()
            ->with(['events' => fn ($query) => $query
                ->whereHas('eventType', fn ($eventTypeQuery) => $eventTypeQuery->where('slug', 'eviction'))
                ->with('latestResult.options')])
            ->get()
            ->flatMap(fn ($round) => $round->events)
            ->flatMap(fn ($event) => $event->latestResult?->options->pluck('houseguest_id')->filter() ?? collect())
            ->unique()
            ->values()
            ->all();

        if ($evictedIds !== []) {
            Houseguest::query()
                ->whereBelongsTo($season)
                ->whereKey($evictedIds)
                ->update(['is_active' => false]);
        }
    }
}
