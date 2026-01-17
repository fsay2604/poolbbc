<?php

use App\Actions\Dashboard\BuildDashboardStats;
use App\Actions\Predictions\RecalculateAllScores;
use App\Http\Requests\Admin\SaveSeasonOutcomeRequest;
use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array<string, mixed> */
    public array $form = [
        'winner_houseguest_id' => null,
        'first_evicted_houseguest_id' => null,
        'top_6_1_houseguest_id' => null,
        'top_6_2_houseguest_id' => null,
        'top_6_3_houseguest_id' => null,
        'top_6_4_houseguest_id' => null,
        'top_6_5_houseguest_id' => null,
        'top_6_6_houseguest_id' => null,
    ];

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();

        if (! $this->season) {
            $this->houseguests = collect();

            return;
        }

        $top6 = is_array($this->season->top_6_houseguest_ids) ? array_values($this->season->top_6_houseguest_ids) : [];

        $selectedHouseguestIds = array_values(array_unique(array_filter([
            $this->season->winner_houseguest_id,
            $this->season->first_evicted_houseguest_id,
            ...$top6,
        ])));

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->when(
                $selectedHouseguestIds !== [],
                fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selectedHouseguestIds)),
                fn ($q) => $q->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $this->form = [
            'winner_houseguest_id' => $this->season->winner_houseguest_id,
            'first_evicted_houseguest_id' => $this->season->first_evicted_houseguest_id,
            'top_6_1_houseguest_id' => $top6[0] ?? null,
            'top_6_2_houseguest_id' => $top6[1] ?? null,
            'top_6_3_houseguest_id' => $top6[2] ?? null,
            'top_6_4_houseguest_id' => $top6[3] ?? null,
            'top_6_5_houseguest_id' => $top6[4] ?? null,
            'top_6_6_houseguest_id' => $top6[5] ?? null,
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        abort_if($this->season === null, 404);

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $request = (new SaveSeasonOutcomeRequest())->setHouseguestIds($houseguestIds);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $this->season->forceFill([
            'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
            'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
            'top_6_houseguest_ids' => [
                $validated['form']['top_6_1_houseguest_id'],
                $validated['form']['top_6_2_houseguest_id'],
                $validated['form']['top_6_3_houseguest_id'],
                $validated['form']['top_6_4_houseguest_id'],
                $validated['form']['top_6_5_houseguest_id'],
                $validated['form']['top_6_6_houseguest_id'],
            ],
        ])->save();

        $admin = Auth::user();
        abort_if($admin === null, 403);

        app(RecalculateAllScores::class)->run(
            season: $this->season->refresh(),
            admin: $admin,
            updateSeasonOutcome: false,
        );

        app(BuildDashboardStats::class)->forget($this->season);

        $this->dispatch('season-outcome-saved');
    }
};
