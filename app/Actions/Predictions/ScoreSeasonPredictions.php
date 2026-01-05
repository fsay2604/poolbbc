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
        $top6 = is_array($season->top_6_houseguest_ids) ? array_values($season->top_6_houseguest_ids) : [];
        $hasFullOutcome = $season->winner_houseguest_id !== null
            && $season->first_evicted_houseguest_id !== null
            && count($top6) === 6;

        if (! $hasFullOutcome) {
            return false;
        }

        // End of the 16 weeks: only score once week 16 has an outcome.
        return $season->weeks()
            ->where('number', 16)
            ->whereHas('outcome')
            ->exists();
    }
}
