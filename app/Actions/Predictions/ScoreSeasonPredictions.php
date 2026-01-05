<?php

namespace App\Actions\Predictions;

use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use Illuminate\Support\Carbon;

class ScoreSeasonPredictions
{
    public function __construct(public ScoreSeasonPrediction $scoreSeasonPrediction) {}

    public function run(Season $season, ?User $admin = null, ?Carbon $now = null): void
    {
        $now ??= now();

        if (! $this->isReadyToScore($season)) {
            return;
        }

        SeasonPrediction::query()
            ->with('user')
            ->where('season_id', $season->id)
            ->whereHas('user')
            ->each(function (SeasonPrediction $prediction) use ($season, $now): void {
                $scored = $this->scoreSeasonPrediction->score($prediction, $season);

                SeasonPredictionScore::query()->updateOrCreate(
                    ['season_prediction_id' => $prediction->id],
                    [
                        'season_id' => $prediction->season_id,
                        'user_id' => $prediction->user_id,
                        'points' => $scored['points'],
                        'breakdown' => $scored['breakdown'],
                        'calculated_at' => $now,
                    ],
                );
            });
    }

    private function isReadyToScore(Season $season): bool
    {
        $hasTop6 = is_array($season->top_6_houseguest_ids) && count(array_values($season->top_6_houseguest_ids)) === 6;

        return $season->winner_houseguest_id !== null
            || $season->first_evicted_houseguest_id !== null
            || $hasTop6;
    }
}
