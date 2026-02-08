<?php

use App\Http\Requests\Admin\SaveWeekRequest;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    /** @var array<string, mixed> */
    public array $form = [
        'number' => 1,
        'boss_count' => 1,
        'nominee_count' => 2,
        'evicted_count' => 1,
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
        $this->form = [
            'number' => $nextNumber,
            'boss_count' => 1,
            'nominee_count' => 2,
            'evicted_count' => 1,
            'name' => null,
            'is_locked' => true,
            'auto_lock_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function edit(int $weekId): void
    {
        $week = Week::query()->findOrFail($weekId);
        $this->editingId = $week->id;
        $this->form = [
            'number' => $week->number,
            'boss_count' => $week->boss_count ?? 1,
            'nominee_count' => $week->nominee_count ?? 2,
            'evicted_count' => $week->evicted_count ?? 1,
            'name' => $week->name,
            'is_locked' => $week->is_locked,
            'auto_lock_at' => $week->auto_lock_at?->format('Y-m-d\TH:i'),
            'starts_at' => $week->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $week->ends_at?->format('Y-m-d\TH:i'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $request = new SaveWeekRequest();
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
        $normalizedForm = $this->normalizeOptionalDateTimes($validated['form']);

        $week = $this->editingId ? Week::query()->findOrFail($this->editingId) : new Week(['season_id' => $this->season->id]);

        $week->fill(array_merge($normalizedForm, ['season_id' => $this->season->id]));
        $week->save();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('week-saved');
    }

    /**
     * @param array<string, mixed> $validatedForm
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

    private function refresh(): void
    {
        $this->weeks = Week::query()
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('number')
            ->get();

        if ($this->editingId === null) {
            $this->startCreate();
        }
    }
};
