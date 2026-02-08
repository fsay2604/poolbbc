<?php

use App\Http\Requests\Admin\SaveWeekOutcomeRequest;
use App\Models\Houseguest;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Actions\Dashboard\BuildDashboardStats;
use App\Actions\Predictions\RecalculateAllScores;
use Livewire\Component;

new class extends Component {
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?WeekOutcome $outcome = null;

    /** @var array<string, mixed> */
    public array $form = [
        'boss_houseguest_ids' => [],
        'nominee_houseguest_ids' => [],
        'veto_winner_houseguest_id' => null,
        'veto_used' => null,
        'saved_houseguest_id' => null,
        'replacement_nominee_houseguest_id' => null,
        'evicted_houseguest_ids' => [],
    ];

    public function mount(Week $week): void
    {
        Gate::authorize('admin');

        $this->week = $week->loadMissing('season', 'outcome');
        $this->outcome = $this->week->outcome;

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $selectedHouseguestIds = [];

        if ($this->outcome) {
            $bosses = $this->normalizeIdList($this->outcome->boss_houseguest_ids);
            if (count($bosses) === 0) {
                $bosses = $this->normalizeIdList([$this->outcome->hoh_houseguest_id]);
            }

            $nominees = $this->normalizeIdList($this->outcome->nominee_houseguest_ids);
            if (count($nominees) === 0) {
                $nominees = $this->normalizeIdList([
                    $this->outcome->nominee_1_houseguest_id,
                    $this->outcome->nominee_2_houseguest_id,
                ]);
            }

            $evicted = $this->normalizeIdList($this->outcome->evicted_houseguest_ids);
            if (count($evicted) === 0) {
                $evicted = $this->normalizeIdList([$this->outcome->evicted_houseguest_id]);
            }

            $selectedHouseguestIds = array_values(array_unique(array_merge(
                $bosses,
                $nominees,
                $evicted,
                $this->normalizeIdList([$this->outcome->veto_winner_houseguest_id]),
                $this->normalizeIdList([$this->outcome->saved_houseguest_id]),
                $this->normalizeIdList([$this->outcome->replacement_nominee_houseguest_id]),
            )));

            $this->form = [
                'boss_houseguest_ids' => $this->padToCount($bosses, $bossCount),
                'nominee_houseguest_ids' => $this->padToCount($nominees, $nomineeCount),
                'veto_winner_houseguest_id' => $this->outcome->veto_winner_houseguest_id,
                'veto_used' => $this->normalizeVetoUsedSelectValue($this->outcome->veto_used),
                'saved_houseguest_id' => $this->outcome->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->outcome->replacement_nominee_houseguest_id,
                'evicted_houseguest_ids' => $this->padToCount($evicted, $evictedCount),
            ];
        } else {
            $this->form['boss_houseguest_ids'] = $this->padToCount([], $bossCount);
            $this->form['nominee_houseguest_ids'] = $this->padToCount([], $nomineeCount);
            $this->form['evicted_houseguest_ids'] = $this->padToCount([], $evictedCount);
        }

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->week->season_id)
            ->when(
                $selectedHouseguestIds !== [],
                fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selectedHouseguestIds)),
                fn ($q) => $q->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function bossCount(): int
    {
        return max(1, (int) ($this->week->boss_count ?? 1));
    }

    private function nomineeCount(): int
    {
        return max(1, (int) ($this->week->nominee_count ?? 2));
    }

    private function evictedCount(): int
    {
        return max(1, (int) ($this->week->evicted_count ?? 1));
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($id): ?int => is_numeric($id) ? (int) $id : null,
            $value,
        )));

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<?int>
     */
    private function padToCount(array $ids, int $count): array
    {
        $padded = array_slice(array_values($ids), 0, $count);

        while (count($padded) < $count) {
            $padded[] = null;
        }

        return $padded;
    }

    public function updatedFormVetoUsed(mixed $value): void
    {
        $this->form['veto_used'] = $this->normalizeVetoUsedSelectValue($value);

        if ($this->form['veto_used'] !== '1') {
            $this->form['saved_houseguest_id'] = null;
            $this->form['replacement_nominee_houseguest_id'] = null;
        }
    }

    private function normalizeVetoUsedSelectValue(mixed $value): ?string
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => '1',
            $value === false, $value === 0, $value === '0' => '0',
            default => null,
        };
    }

    public function save(): void
    {
        Gate::authorize('admin');

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $request = (new SaveWeekOutcomeRequest())->setContext($houseguestIds, $bossCount, $nomineeCount, $evictedCount);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $bosses = $this->padToCount($this->normalizeIdList($validated['form']['boss_houseguest_ids'] ?? []), $bossCount);
        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

        if (! ($validated['form']['veto_used'] ?? false)) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $data = array_merge(
            $validated['form'],
            [
                'boss_houseguest_ids' => $bosses,
                'hoh_houseguest_id' => $bosses[0] ?? null,
                'nominee_houseguest_ids' => $nominees,
                'evicted_houseguest_ids' => $evicted,
                'nominee_1_houseguest_id' => $nominees[0] ?? null,
                'nominee_2_houseguest_id' => $nominees[1] ?? null,
                'evicted_houseguest_id' => $evicted[0] ?? null,
            ],
        );

        $outcome = WeekOutcome::query()->updateOrCreate(
            ['week_id' => $this->week->id],
            array_merge(
                $data,
                [
                    'last_admin_edited_by_user_id' => Auth::id(),
                    'last_admin_edited_at' => now(),
                ],
            ),
        );

        $evictedIds = array_values(array_filter($this->normalizeIdList($evicted)));
        if ($evictedIds !== []) {
            Houseguest::query()
                ->where('season_id', $this->week->season_id)
                ->whereIn('id', $evictedIds)
                ->update(['is_active' => false]);
        }

        if ($this->week->season) {
            $admin = Auth::user();
            abort_if($admin === null, 403);

            app(RecalculateAllScores::class)->run(season: $this->week->season->refresh(), admin: $admin);
        }

        app(BuildDashboardStats::class)->forget($this->week->season);

        $this->outcome = $outcome;
        $this->dispatch('outcome-saved');
    }
};
