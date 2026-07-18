<?php

use App\Actions\Predictions\StoreWeekPrediction;
use App\Actions\Weeks\WeekPhaseManager;
use App\Http\Requests\Weeks\ConfirmWeekPredictionRequest;
use App\Http\Requests\Weeks\SaveWeekPredictionRequest;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Week;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array<string, mixed> */
    public array $form = [
        'phases' => [],
    ];

    public ?Prediction $prediction = null;

    public function mount(Week $week): void
    {
        $this->week = $week->loadMissing('season', 'phases');
        $this->phaseManager()->ensureDefaultPhases($this->week);
        $this->week->load('phases');

        $this->prediction = Prediction::query()
            ->where('week_id', $this->week->id)
            ->where('user_id', Auth::id())
            ->first();

        $isLocked = $this->week->isLocked();
        $existingPayload = $this->prediction?->phase_picks;
        if ($this->prediction !== null && (! is_array($existingPayload) || $existingPayload === [])) {
            $existingPayload = $this->phaseManager()->legacyPayloadForModel($this->prediction, $this->week->phases);
        }

        $selectedHouseguestIds = $this->phaseManager()->selectedHouseguestIds($existingPayload);

        $this->form['phases'] = $this->phaseManager()->buildSelectionRows(
            $this->week->phases,
            $existingPayload
        );

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

    public function getIsLockedProperty(): bool
    {
        return $this->week->isLocked();
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

    public function save(StoreWeekPrediction $storeWeekPrediction): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $this->normalizeVetoSwitchValues();

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $phaseDefinitions = $this->phaseManager()->phaseDefinitionsForValidation($this->week->phases);

        $request = (new SaveWeekPredictionRequest)->setContext(
            $houseguestIds,
            $phaseDefinitions,
        );
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $payload = $this->phaseManager()->normalizeSelectionRows($validated['form']['phases'] ?? [], $this->week->phases);

        $user = Auth::user();
        abort_if($user === null, 403);
        $this->prediction = $storeWeekPrediction->handle($this->week, $user, $payload, false);

        $this->dispatch('prediction-saved');
    }

    public function confirm(StoreWeekPrediction $storeWeekPrediction): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $this->normalizeVetoSwitchValues();

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $phaseDefinitions = $this->phaseManager()->phaseDefinitionsForValidation($this->week->phases);

        $request = (new ConfirmWeekPredictionRequest)->setContext(
            $houseguestIds,
            $phaseDefinitions,
        );
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $payload = $this->phaseManager()->normalizeSelectionRows($validated['form']['phases'] ?? [], $this->week->phases);

        $user = Auth::user();
        abort_if($user === null, 403);
        $this->prediction = $storeWeekPrediction->handle($this->week, $user, $payload, true);
        $this->dispatch('prediction-confirmed');
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
