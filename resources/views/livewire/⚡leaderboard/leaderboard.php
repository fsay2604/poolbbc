<?php

use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    /** @var \Illuminate\Support\Collection<int, array{user:\App\Models\User,total:int,season:int,by_week:array<int,int>}> */
    public $rows;

    public function mount(): void
    {
        $this->season = Season::query()->where('is_active', true)->first();

        $this->weeks = Week::query()
            ->when(
                $this->season,
                fn (Builder $q) => $q->where('season_id', $this->season->id),
                fn (Builder $q) => $q->whereRaw('1=0'),
            )
            ->orderBy('number')
            ->get();

        if (! $this->season) {
            $this->rows = collect();

            return;
        }

        $weeklyScores = DB::table('prediction_scores')
            ->join('weeks', 'weeks.id', '=', 'prediction_scores.week_id')
            ->where('weeks.season_id', $this->season->id)
            ->select([
                'prediction_scores.user_id',
                'prediction_scores.week_id',
                'prediction_scores.points',
            ])
            ->get();

        $seasonScores = DB::table('season_prediction_scores')
            ->where('season_id', $this->season->id)
            ->select([
                'user_id',
                'points',
            ])
            ->get();

        $userIds = $weeklyScores
            ->pluck('user_id')
            ->merge($seasonScores->pluck('user_id'))
            ->unique()
            ->values();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $rows = $userIds->map(function (int $userId) use ($weeklyScores, $seasonScores, $users): array {
            $byWeek = $weeklyScores
                ->where('user_id', $userId)
                ->keyBy('week_id')
                ->map(fn ($row) => (int) $row->points)
                ->all();

            $weeklyTotal = array_sum($byWeek);
            $seasonPoints = (int) ($seasonScores->firstWhere('user_id', $userId)->points ?? 0);
            $total = $weeklyTotal + $seasonPoints;

            return [
                'user' => $users[$userId],
                'total' => $total,
                'season' => $seasonPoints,
                'by_week' => $byWeek,
            ];
        })->sortByDesc('total')->values();

        $this->rows = $rows;
    }
};
