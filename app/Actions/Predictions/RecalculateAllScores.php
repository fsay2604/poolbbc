<?php

namespace App\Actions\Predictions;

use App\Actions\Seasons\CalculateSeasonOutcomeFromWeekOutcomes;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Support\LegacyFlowAuthority;
use Illuminate\Validation\ValidationException;

class RecalculateAllScores
{
    public function __construct(
        public ScoreWeek $scoreWeek,
        public ScoreSeasonPredictions $scoreSeasonPredictions,
        public CalculateSeasonOutcomeFromWeekOutcomes $calculateSeasonOutcomeFromWeekOutcomes,
        private LegacyFlowAuthority $legacyFlowAuthority,
    ) {}

    public function run(?Season $season = null, ?User $admin = null, bool $updateSeasonOutcome = true): void
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages([
                'scoring' => __('Legacy score recalculation is read-only after canonical cutover.'),
            ]);
        }

        $season ??= Season::query()->where('is_active', true)->first();

        if (! $season) {
            return;
        }

        $weeks = Week::query()
            ->where('season_id', $season->id)
            ->orderBy('number')
            ->get();

        foreach ($weeks as $week) {
            $this->scoreWeek->run($week, $admin);
        }

        if ($updateSeasonOutcome) {
            $this->calculateSeasonOutcomeFromWeekOutcomes->execute($season);
        }

        $this->scoreSeasonPredictions->run($season->refresh(), $admin);
    }
}
