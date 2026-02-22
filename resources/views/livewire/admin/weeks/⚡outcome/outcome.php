<?php

use App\Actions\Dashboard\BuildDashboardStats;
use App\Actions\Predictions\RecalculateAllScores;
use App\Actions\Weeks\WeekPhaseManager;
use App\Http\Requests\Admin\SaveWeekOutcomeRequest;
use App\Models\Houseguest;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?WeekOutcome $outcome = null;

    /** @var array<string, mixed> */
    public array $form = [
        'phases' => [],
    ];

    public function mount(Week $week): void
    {
        Gate::authorize('admin');

        $this->week = $week->loadMissing('season', 'phases', 'outcome');
        $this->phaseManager()->ensureDefaultPhases($this->week);
        $this->week->load('phases', 'outcome');

        $this->outcome = $this->week->outcome;
        $existingPayload = $this->outcome?->phase_results;
        if ($this->outcome !== null && (! is_array($existingPayload) || $existingPayload === [])) {
            $existingPayload = $this->phaseManager()->legacyPayloadForModel($this->outcome, $this->week->phases);
        }

        $this->form['phases'] = $this->phaseManager()->buildSelectionRows(
            $this->week->phases,
            $existingPayload
        );

        $selectedHouseguestIds = $this->phaseManager()->selectedHouseguestIds($existingPayload);

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

    public function save(): void
    {
        Gate::authorize('admin');

        $this->normalizeVetoSwitchValues();

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $phaseDefinitions = $this->phaseManager()->phaseDefinitionsForValidation($this->week->phases);

        $request = (new SaveWeekOutcomeRequest())->setContext($houseguestIds, $phaseDefinitions);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $payload = $this->phaseManager()->normalizeSelectionRows($validated['form']['phases'] ?? [], $this->week->phases);

        $outcome = WeekOutcome::query()->updateOrCreate(
            ['week_id' => $this->week->id],
            [
                'phase_results' => $payload,
                'last_admin_edited_by_user_id' => Auth::id(),
                'last_admin_edited_at' => now(),
            ],
        );

        $evictedIds = $this->phaseManager()->evictedIds($payload);
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

    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^form\.phases\.(\d+)\.veto_used$/', $name, $matches) !== 1) {
            return;
        }

        $phaseIndex = (int) $matches[1];
        if (! isset($this->form['phases'][$phaseIndex])) {
            return;
        }

        if (($this->form['phases'][$phaseIndex]['type'] ?? null) !== WeekPhaseManager::TYPE_VETO) {
            return;
        }

        $this->form['phases'][$phaseIndex]['veto_used'] = $this->phaseManager()->normalizeVetoUsedValue($value);
    }

    /**
     * @return array<string, string>
     */
    public function selectionLabels(string $type): array
    {
        return $this->phaseManager()->selectionLabels($type);
    }

    public function phaseTypeLabel(string $type): string
    {
        return $this->phaseManager()->phaseTypeLabel($type);
    }

    private function normalizeVetoSwitchValues(): void
    {
        foreach ($this->form['phases'] as $index => $phase) {
            if (($phase['type'] ?? null) !== WeekPhaseManager::TYPE_VETO) {
                continue;
            }

            $this->form['phases'][$index]['veto_used'] = $this->phaseManager()->normalizeVetoUsedValue(
                $phase['veto_used'] ?? false
            );
        }
    }

    private function phaseManager(): WeekPhaseManager
    {
        return app(WeekPhaseManager::class);
    }
};
