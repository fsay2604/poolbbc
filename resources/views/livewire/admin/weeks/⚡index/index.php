<?php

use App\Actions\Weeks\WeekPhaseManager;
use App\Http\Requests\Admin\SaveWeekRequest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    /** @var array<string, mixed> */
    public array $form = [
        'number' => 1,
        'phases' => [],
        'name' => null,
        'is_locked' => true,
        'auto_lock_at' => null,
        'starts_at' => null,
        'ends_at' => null,
    ];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();
        $this->refresh();
    }

    public function startCreate(): void
    {
        $nextNumber = (int) (Week::query()->when($this->season, fn ($q) => $q->where('season_id', $this->season->id))->max('number') ?? 0) + 1;
        $this->editingId = null;

        $phaseRows = [];
        foreach ($this->phaseManager()->defaultPhaseDefinitions() as $definition) {
            $phaseRows[] = $this->phaseFormRowFromDefinition($definition);
        }

        $this->form = [
            'number' => $nextNumber,
            'phases' => $phaseRows,
            'name' => null,
            'is_locked' => true,
            'auto_lock_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function edit(int $weekId): void
    {
        $week = Week::query()
            ->with('phases')
            ->findOrFail($weekId);

        $this->phaseManager()->ensureDefaultPhases($week);
        $week->load('phases');

        $this->editingId = $week->id;
        $this->form = [
            'number' => $week->number,
            'phases' => $week->phases
                ->map(function ($phase): array {
                    $row = [
                        'id' => $phase->id,
                        'position' => $phase->position,
                        'type' => $phase->type,
                        'hoh_count' => 0,
                        'nominee_count' => 0,
                        'winner_count' => 0,
                        'saved_count' => 0,
                        'replacement_count' => 0,
                        'evicted_count' => 0,
                    ];

                    $config = $this->phaseManager()->normalizeConfig($phase->type, is_array($phase->config) ? $phase->config : []);
                    foreach ($config as $key => $value) {
                        $row[$key] = $value;
                    }

                    return $row;
                })
                ->values()
                ->all(),
            'name' => $week->name,
            'is_locked' => $week->is_locked,
            'auto_lock_at' => $week->auto_lock_at?->format('Y-m-d\TH:i'),
            'starts_at' => $week->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $week->ends_at?->format('Y-m-d\TH:i'),
        ];
    }

    public function addPhase(): void
    {
        $nextPosition = count($this->form['phases']) + 1;
        $this->form['phases'][] = [
            'id' => null,
            'position' => $nextPosition,
            'type' => WeekPhaseManager::TYPE_HOH,
            'hoh_count' => 1,
            'nominee_count' => 0,
            'winner_count' => 0,
            'saved_count' => 0,
            'replacement_count' => 0,
            'evicted_count' => 0,
        ];
    }

    public function removePhase(int $index): void
    {
        if (! isset($this->form['phases'][$index])) {
            return;
        }

        unset($this->form['phases'][$index]);
        $this->form['phases'] = array_values($this->form['phases']);
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $request = new SaveWeekRequest;
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
        $normalizedForm = $this->normalizeOptionalDateTimes($validated['form']);
        $normalizedPhases = $this->phaseManager()->normalizeWeekFormRows($normalizedForm['phases'] ?? []);

        $week = $this->editingId ? Week::query()->with('phases')->findOrFail($this->editingId) : new Week(['season_id' => $this->season->id]);

        if ($week->exists
            && ($week->predictions()->exists() || $week->outcome()->exists())
            && $this->phaseDefinitionsDiffer($week, $normalizedPhases)) {
            throw ValidationException::withMessages([
                'form.phases' => __('Phase definitions cannot be changed after the first response.'),
            ]);
        }

        $week->fill(array_merge($normalizedForm, ['season_id' => $this->season->id]));
        $week->save();

        $existingPhases = $week->phases()->get()->keyBy('id');
        $keptPhaseIds = [];

        foreach ($normalizedPhases as $phaseData) {
            $phaseId = $phaseData['id'];
            if ($phaseId !== null && $existingPhases->has($phaseId)) {
                $phase = $existingPhases->get($phaseId);
                $phase->fill([
                    'position' => $phaseData['position'],
                    'type' => $phaseData['type'],
                    'config' => $phaseData['config'],
                ])->save();

                $keptPhaseIds[] = $phase->id;

                continue;
            }

            $created = $week->phases()->create([
                'position' => $phaseData['position'],
                'type' => $phaseData['type'],
                'config' => $phaseData['config'],
            ]);
            $keptPhaseIds[] = $created->id;
        }

        $week->phases()
            ->when($keptPhaseIds !== [], fn ($q) => $q->whereNotIn('id', $keptPhaseIds))
            ->delete();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('week-saved');
    }

    public function phaseTypeLabel(string $type): string
    {
        return $this->phaseManager()->phaseTypeLabel($type);
    }

    /**
     * @param  array{position:int, type:string, config:array<string, int>}  $definition
     * @return array<string, mixed>
     */
    private function phaseFormRowFromDefinition(array $definition): array
    {
        $row = [
            'id' => null,
            'position' => $definition['position'],
            'type' => $definition['type'],
            'hoh_count' => 0,
            'nominee_count' => 0,
            'winner_count' => 0,
            'saved_count' => 0,
            'replacement_count' => 0,
            'evicted_count' => 0,
        ];

        foreach ($definition['config'] as $key => $value) {
            $row[$key] = $value;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $validatedForm
     * @return array<string, mixed>
     */
    private function normalizeOptionalDateTimes(array $validatedForm): array
    {
        foreach (['auto_lock_at', 'starts_at', 'ends_at'] as $attribute) {
            if (($validatedForm[$attribute] ?? null) === '') {
                $validatedForm[$attribute] = null;
            }
        }

        return $validatedForm;
    }

    private function reconcileWeekPayloads(Week $week): void
    {
        $phases = $week->phases()->orderBy('position')->get();

        Prediction::query()
            ->where('week_id', $week->id)
            ->get()
            ->each(function (Prediction $prediction) use ($phases): void {
                $payload = $prediction->phase_picks;
                if (! is_array($payload) || $payload === []) {
                    $payload = $this->phaseManager()->legacyPayloadForModel($prediction, $phases);
                }

                $prediction->phase_picks = $this->phaseManager()->reconcilePayload($phases, $payload);
                $prediction->save();
            });

        $outcome = WeekOutcome::query()->where('week_id', $week->id)->first();
        if ($outcome !== null) {
            $payload = $outcome->phase_results;
            if (! is_array($payload) || $payload === []) {
                $payload = $this->phaseManager()->legacyPayloadForModel($outcome, $phases);
            }

            $outcome->phase_results = $this->phaseManager()->reconcilePayload($phases, $payload);
            $outcome->save();
        }
    }

    /** @param list<array{id:?int, position:int, type:string, config:array<string, int>}> $incoming */
    private function phaseDefinitionsDiffer(Week $week, array $incoming): bool
    {
        $current = $week->phases
            ->map(fn ($phase): array => [
                'id' => $phase->id,
                'position' => $phase->position,
                'type' => $phase->type,
                'config' => $phase->config,
            ])
            ->values()
            ->all();

        return $current !== $incoming;
    }

    private function refresh(): void
    {
        $this->weeks = Week::query()
            ->with('phases')
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('number')
            ->get();

        if ($this->editingId === null) {
            $this->startCreate();
        }
    }

    private function phaseManager(): WeekPhaseManager
    {
        return app(WeekPhaseManager::class);
    }
};
