<?php

namespace App\Actions\Predictions;

use App\Models\Prediction;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PredictionVisibility
{
    /**
     * @param  Collection<int, \App\Models\Week>  $weeks
     * @return Collection<int, Prediction>
     */
    public function weeklyFor(User $viewer, User $subject, Collection $weeks): Collection
    {
        return Prediction::query()
            ->with('score')
            ->whereBelongsTo($subject)
            ->whereIn('week_id', $weeks->modelKeys())
            ->when($viewer->isNot($subject), function (Builder $query): void {
                $query
                    ->whereNotNull('confirmed_at')
                    ->whereHas('week', fn (Builder $weekQuery): Builder => $weekQuery
                        ->where('is_locked', true)
                        ->orWhere(fn (Builder $deadlineQuery): Builder => $deadlineQuery
                            ->whereNotNull('auto_lock_at')
                            ->where('auto_lock_at', '<=', now())));
            })
            ->get()
            ->keyBy('week_id');
    }

    public function seasonFor(User $viewer, User $subject, Season $season): ?SeasonPrediction
    {
        return SeasonPrediction::query()
            ->whereBelongsTo($season)
            ->whereBelongsTo($subject)
            ->when($viewer->isNot($subject), fn (Builder $query): Builder => $query
                ->whereNotNull('confirmed_at')
                ->whereHas('season', fn (Builder $seasonQuery): Builder => $seasonQuery
                    ->whereNotNull('prediction_locks_at')
                    ->where('prediction_locks_at', '<=', now())))
            ->first();
    }
}
