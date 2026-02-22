<?php

use App\Actions\Weeks\WeekPhaseManager;
use App\Http\Requests\Admin\UpdatePredictionRequest;
use App\Models\Houseguest;
use App\Models\Prediction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Prediction $prediction;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array<string, mixed> */
    public array $form = [
        'phases' => [],
        'confirmed_at' => null,
    ];

    public function mount(Prediction $prediction): void
    {
        Gate::authorize('admin');

        $this->prediction = $prediction->loadMissing('user', 'week.season', 'week.phases');
        $this->phaseManager()->ensureDefaultPhases($this->prediction->week);
        $this->prediction->week->load('phases');

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->prediction->week->season_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $existingPayload = $this->prediction->phase_picks;
        if (! is_array($existingPayload) || $existingPayload === []) {
            $existingPayload = $this->phaseManager()->legacyPayloadForModel($this->prediction, $this->prediction->week->phases);
        }

        $this->form = [
            'phases' => $this->phaseManager()->buildSelectionRows(
                $this->prediction->week->phases,
                $existingPayload
            ),
            'confirmed_at' => $this->prediction->confirmed_at?->format('Y-m-d\TH:i'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        $this->normalizeVetoSwitchValues();

        $houseguestIds = $this->houseguests->pluck('id')->all();
        $phaseDefinitions = $this->phaseManager()->phaseDefinitionsForValidation($this->prediction->week->phases);

        $request = (new UpdatePredictionRequest())->setContext($houseguestIds, $phaseDefinitions);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $payload = $this->phaseManager()->normalizeSelectionRows($validated['form']['phases'] ?? [], $this->prediction->week->phases);

        $this->prediction->fill([
            'phase_picks' => $payload,
            'confirmed_at' => $validated['form']['confirmed_at'] ?? null,
        ]);
        $this->prediction->last_admin_edited_by_user_id = Auth::id();
        $this->prediction->last_admin_edited_at = now();
        $this->prediction->admin_edit_count = (int) $this->prediction->admin_edit_count + 1;
        $this->prediction->save();

        $this->dispatch('prediction-admin-saved');
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
