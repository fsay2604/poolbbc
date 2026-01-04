<?php

namespace App\Actions\Predictions;

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;

class ScoreWeek
{
    public function __construct(public ScorePrediction $scorePrediction) {}

    public function run(Week $week, ?User $admin = null, ?Carbon $now = null): void
    {
        $now ??= now();

        $outcome = $week->outcome;
        if ($outcome === null) {
            return;
        }

        $week->predictions()
            ->with('user')
            ->whereHas('user')
            ->each(function (Prediction $prediction) use ($outcome, $now): void {
                $scored = $this->scorePrediction->score($prediction, $outcome);

                PredictionScore::query()->updateOrCreate(
                    ['prediction_id' => $prediction->id],
                    [
                        'week_id' => $prediction->week_id,
                        'user_id' => $prediction->user_id,
                        'points' => $scored['points'],
                        'breakdown' => $scored['breakdown'],
                        'calculated_at' => $now,
                    ],
                );
            });
    }
}
