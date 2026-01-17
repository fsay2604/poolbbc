<?php

use App\Actions\Seasons\CreateDefaultWeeks;
use App\Http\Requests\Admin\SaveSeasonRequest;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    /** @var \Illuminate\Support\Collection<int, \App\Models\Season> */
    public $seasons;

    public ?int $confirmingSeasonDeletionId = null;
    public ?string $confirmingSeasonDeletionName = null;

    public bool $showConfirmSeasonDeletionModal = false;

    /** @var array{name:string,is_active:bool,starts_on:?string,ends_on:?string} */
    public array $form = [
        'name' => '',
        'is_active' => false,
        'starts_on' => null,
        'ends_on' => null,
    ];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'is_active' => false,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    public function edit(int $seasonId): void
    {
        $season = Season::query()->findOrFail($seasonId);

        $this->editingId = $season->id;
        $this->form = [
            'name' => $season->name,
            'is_active' => $season->is_active,
            'starts_on' => $season->starts_on?->format('Y-m-d'),
            'ends_on' => $season->ends_on?->format('Y-m-d'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        $isCreating = $this->editingId === null;

        $request = new SaveSeasonRequest();
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $season = $this->editingId
            ? Season::query()->findOrFail($this->editingId)
            : new Season();

        $season->fill($validated['form']);

        if (($validated['form']['is_active'] ?? false) === true) {
            Season::query()->where('id', '!=', $season->id)->update(['is_active' => false]);
        }

        $season->save();

        if ($isCreating) {
            app(CreateDefaultWeeks::class)->run($season);
        }

        $this->startCreate();
        $this->refresh();

        $this->dispatch('season-saved');
    }

    public function confirmDelete(int $seasonId): void
    {
        Gate::authorize('admin');

        $season = Season::query()->findOrFail($seasonId);

        $this->confirmingSeasonDeletionId = $season->id;
        $this->confirmingSeasonDeletionName = $season->name;

        $this->showConfirmSeasonDeletionModal = true;
    }

    public function delete(int $seasonId): void
    {
        Gate::authorize('admin');

        DB::transaction(function () use ($seasonId): void {
            $season = Season::query()->findOrFail($seasonId);
            $season->delete();
        });

        if ($this->editingId === $seasonId) {
            $this->startCreate();
        }

        $this->refresh();

        $this->dispatch('season-deleted');
    }

    public function deleteSelectedSeason(): void
    {
        abort_if($this->confirmingSeasonDeletionId === null, 422);

        $this->delete($this->confirmingSeasonDeletionId);

        $this->confirmingSeasonDeletionId = null;
        $this->confirmingSeasonDeletionName = null;

        $this->showConfirmSeasonDeletionModal = false;
    }

    private function refresh(): void
    {
        $this->seasons = Season::query()->orderByDesc('is_active')->orderByDesc('id')->get();
    }
};
