<?php

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Seasons\CreateDefaultWeeks;
use App\Http\Requests\Admin\SaveSeasonRequest;
use App\Models\Season;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    /** @var \Illuminate\Support\Collection<int, \App\Models\Season> */
    public $seasons;

    public ?int $confirmingSeasonDeletionId = null;

    public ?string $confirmingSeasonDeletionName = null;

    public bool $showConfirmSeasonDeletionModal = false;

    /** @var array{name:string,is_active:bool,starts_on:?string,ends_on:?string,prediction_opens_at:?string,prediction_locks_at:?string} */
    public array $form = [
        'name' => '',
        'is_active' => false,
        'starts_on' => null,
        'ends_on' => null,
        'prediction_opens_at' => null,
        'prediction_locks_at' => null,
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
            'prediction_opens_at' => null,
            'prediction_locks_at' => null,
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
            'prediction_opens_at' => $season->prediction_opens_at?->format('Y-m-d\TH:i'),
            'prediction_locks_at' => $season->prediction_locks_at?->format('Y-m-d\TH:i'),
        ];
    }

    public function save(RecordAuditLog $recordAuditLog): void
    {
        Gate::authorize('admin');

        $isCreating = $this->editingId === null;

        $request = new SaveSeasonRequest;
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
        $auditAttributes = array_keys($validated['form']);

        DB::transaction(function () use ($isCreating, $validated, $auditAttributes, $recordAuditLog): void {
            $season = $isCreating
                ? new Season
                : Season::query()->lockForUpdate()->findOrFail($this->editingId);
            $before = $isCreating ? null : $season->only($auditAttributes);

            if (($validated['form']['is_active'] ?? false) === true) {
                Season::query()
                    ->where('is_active', true)
                    ->when($season->exists, fn ($query) => $query->whereKeyNot($season->id))
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Season $activeSeason) use ($recordAuditLog): void {
                        $activeSeason->update(['is_active' => false]);
                        $recordAuditLog->handle(null, auth()->user(), 'season.deactivated', $activeSeason, [
                            'before' => ['is_active' => true],
                            'after' => ['is_active' => false],
                        ]);
                    });
            }

            $season->fill($validated['form']);
            $season->save();

            if ($isCreating) {
                app(CreateDefaultWeeks::class)->run($season);
            }

            $recordAuditLog->handle(null, auth()->user(), $isCreating ? 'season.created' : 'season.updated', $season, [
                'before' => $before,
                'after' => $season->only($auditAttributes),
            ]);
        });

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

    public function delete(int $seasonId, RecordAuditLog $recordAuditLog): void
    {
        Gate::authorize('admin');

        DB::transaction(function () use ($seasonId, $recordAuditLog): void {
            $season = Season::query()->lockForUpdate()->findOrFail($seasonId);
            $before = $season->only([
                'name',
                'is_active',
                'starts_on',
                'ends_on',
                'prediction_opens_at',
                'prediction_locks_at',
            ]);

            $recordAuditLog->handle(null, auth()->user(), 'season.deleted', $season, [
                'before' => $before,
                'after' => null,
            ]);
            $season->delete();
        });

        if ($this->editingId === $seasonId) {
            $this->startCreate();
        }

        $this->refresh();

        $this->dispatch('season-deleted');
    }

    public function deleteSelectedSeason(RecordAuditLog $recordAuditLog): void
    {
        abort_if($this->confirmingSeasonDeletionId === null, 422);

        $this->delete($this->confirmingSeasonDeletionId, $recordAuditLog);

        $this->confirmingSeasonDeletionId = null;
        $this->confirmingSeasonDeletionName = null;

        $this->showConfirmSeasonDeletionModal = false;
    }

    private function refresh(): void
    {
        $this->seasons = Season::query()->orderByDesc('is_active')->orderByDesc('id')->get();
    }
};
