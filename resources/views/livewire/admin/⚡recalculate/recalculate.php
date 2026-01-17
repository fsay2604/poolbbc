<?php

use App\Actions\Predictions\ScoreWeek;
use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Actions\Seasons\CalculateSeasonOutcomeFromWeekOutcomes;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    public ?string $weekId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $season = Season::query()->where('is_active', true)->first();

        $this->weeks = Week::query()
            ->when($season, fn ($q) => $q->where('season_id', $season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('number')
            ->get();
    }

    public function recalculate(
        ScoreWeek $scoreWeek,
        ScoreSeasonPredictions $scoreSeasonPredictions,
        CalculateSeasonOutcomeFromWeekOutcomes $calculateSeasonOutcomeFromWeekOutcomes,
    ): void
    {
        Gate::authorize('admin');

        $admin = Auth::user();
        abort_if($admin === null, 403);

        $weekId = is_numeric($this->weekId) ? (int) $this->weekId : null;

        $weeks = $weekId
            ? Week::query()->whereKey($weekId)->get()
            : $this->weeks;

        foreach ($weeks as $week) {
            $scoreWeek->run($week, $admin);
        }

        $season = Season::query()->where('is_active', true)->first();
        if ($season) {
            $calculateSeasonOutcomeFromWeekOutcomes->execute($season);
            $scoreSeasonPredictions->run($season, $admin);
        }

        $this->dispatch('scores-recalculated');
    }
};
