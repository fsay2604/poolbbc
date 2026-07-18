<?php

namespace App\Actions\Predictions;

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Support\LegacyFlowAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ScoreWeek
{
    public function __construct(
        public ScorePrediction $scorePrediction,
        private LegacyFlowAuthority $legacyFlowAuthority,
    ) {}

    public function run(Week $week, ?User $admin = null, ?Carbon $now = null): void
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages([
                'scoring' => __('Legacy week scoring is read-only after canonical cutover.'),
            ]);
        }

        $now ??= now();

        $outcome = $week->outcome;
        if ($outcome === null) {
            return;
        }

        PredictionScore::query()
            ->where('week_id', $week->id)
            ->whereHas('prediction', fn ($query) => $query->whereNull('confirmed_at'))
            ->delete();

        $week->predictions()
            ->with(['user', 'week.phases'])
            ->whereNotNull('confirmed_at')
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
