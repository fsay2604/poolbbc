<?php

use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

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
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Leaderboard') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
            @endif
        </div>

        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Player') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ __('Total') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ __('Season') }}</th>
                            @foreach ($weeks as $week)
                                <th class="px-4 py-3 text-right font-medium">{{ __('W').$week->number }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row['user']->name }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $row['total'] }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">
                                    {{ $row['season'] ?? 0 }}
                                </td>
                                @foreach ($weeks as $week)
                                    <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">
                                        {{ $row['by_week'][$week->id] ?? 0 }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400" colspan="{{ 3 + $weeks->count() }}">
                                    {{ __('No scores yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
