<?php

use App\Http\Requests\SeasonPrediction\ConfirmSeasonPredictionRequest;
use App\Http\Requests\SeasonPrediction\SaveSeasonPredictionRequest;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?SeasonPrediction $prediction = null;

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
        $this->season = Season::query()->where('is_active', true)->first();

        if (! $this->season) {
            $this->houseguests = collect();

            return;
        }

        $this->prediction = SeasonPrediction::query()
            ->where('season_id', $this->season->id)
            ->where('user_id', Auth::id())
            ->first();

        $selectedHouseguestIds = [];
        $isLocked = $this->prediction?->isConfirmed() ?? false;

        if ($this->prediction) {
            $top6 = $this->prediction->top_6_houseguest_ids ?? [];

            if ($isLocked) {
                $selectedHouseguestIds = array_values(array_unique(array_filter([
                    $this->prediction->winner_houseguest_id,
                    $this->prediction->first_evicted_houseguest_id,
                    ...$top6,
                ])));
            }

            $this->form = [
                'winner_houseguest_id' => $this->prediction->winner_houseguest_id,
                'first_evicted_houseguest_id' => $this->prediction->first_evicted_houseguest_id,
                'top_6_1_houseguest_id' => $top6[0] ?? null,
                'top_6_2_houseguest_id' => $top6[1] ?? null,
                'top_6_3_houseguest_id' => $top6[2] ?? null,
                'top_6_4_houseguest_id' => $top6[3] ?? null,
                'top_6_5_houseguest_id' => $top6[4] ?? null,
                'top_6_6_houseguest_id' => $top6[5] ?? null,
            ];
        }

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->when(
                $isLocked && $selectedHouseguestIds !== [],
                fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selectedHouseguestIds)),
                fn ($q) => $q->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getIsLockedProperty(): bool
    {
        return $this->prediction?->isConfirmed() ?? false;
    }

    public function save(): void
    {
        abort_if($this->season === null, 422);

        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $request = (new SaveSeasonPredictionRequest())->setHouseguestIds($houseguestIds);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $top6 = [
            $validated['form']['top_6_1_houseguest_id'],
            $validated['form']['top_6_2_houseguest_id'],
            $validated['form']['top_6_3_houseguest_id'],
            $validated['form']['top_6_4_houseguest_id'],
            $validated['form']['top_6_5_houseguest_id'],
            $validated['form']['top_6_6_houseguest_id'],
        ];

        $this->prediction = SeasonPrediction::query()->updateOrCreate(
            [
                'season_id' => $this->season->id,
                'user_id' => Auth::id(),
            ],
            [
                'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
                'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
                'top_6_houseguest_ids' => $top6,
            ],
        );

        $this->dispatch('season-prediction-saved');
    }

    public function confirm(): void
    {
        abort_if($this->season === null, 422);

        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $request = (new ConfirmSeasonPredictionRequest())->setHouseguestIds($houseguestIds);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $top6 = [
            $validated['form']['top_6_1_houseguest_id'],
            $validated['form']['top_6_2_houseguest_id'],
            $validated['form']['top_6_3_houseguest_id'],
            $validated['form']['top_6_4_houseguest_id'],
            $validated['form']['top_6_5_houseguest_id'],
            $validated['form']['top_6_6_houseguest_id'],
        ];

        $prediction = SeasonPrediction::query()->updateOrCreate(
            [
                'season_id' => $this->season->id,
                'user_id' => Auth::id(),
            ],
            [
                'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
                'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
                'top_6_houseguest_ids' => $top6,
            ],
        );

        $prediction->confirm();
        $prediction->save();

        $this->prediction = $prediction;

        $this->dispatch('season-prediction-confirmed');
    }
};
