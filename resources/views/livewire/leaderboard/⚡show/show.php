<?php

use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

new class extends Component {
    public User $user;

    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    /** @var array{total:int,season:int,by_week:array<int,int>} */
    public array $row = [
        'total' => 0,
        'season' => 0,
        'by_week' => [],
    ];

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->season = Season::query()->where('is_active', true)->first();

        $this->weeks = Week::query()
            ->when(
                $this->season,
                fn (Builder $query) => $query->where('season_id', $this->season->id),
                fn (Builder $query) => $query->whereRaw('1=0'),
            )
            ->orderBy('number')
            ->get();

        if (! $this->season) {
            return;
        }

        $weeklyScores = PredictionScore::query()
            ->where('user_id', $this->user->id)
            ->whereHas('week', fn (Builder $query) => $query->where('season_id', $this->season->id))
            ->get(['week_id', 'points']);

        $byWeek = $weeklyScores
            ->keyBy('week_id')
            ->map(fn (PredictionScore $score) => (int) $score->points)
            ->all();

        $weeklyTotal = array_sum($byWeek);

        $seasonPoints = (int) (SeasonPredictionScore::query()
            ->where('season_id', $this->season->id)
            ->where('user_id', $this->user->id)
            ->value('points') ?? 0);

        $this->row = [
            'total' => $weeklyTotal + $seasonPoints,
            'season' => $seasonPoints,
            'by_week' => $byWeek,
        ];
    }
};
