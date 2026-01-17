<?php

use App\Actions\Dashboard\BuildDashboardStats;
use App\Http\Requests\Admin\SaveHouseguestRequest;
use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Livewire\Component;
use App\Enums\Occupation;

new class extends Component {
    use WithFileUploads;

    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public mixed $avatar = null;

    /** @var array{name:string,sex:?string,occupations:array<int,string>,avatar_url:?string,is_active:bool,sort_order:int} */
    public array $form = [
        'name' => '',
        'sex' => 'M',
        'occupations' => [],
        'avatar_url' => null,
        'is_active' => true,
        'sort_order' => 0,
    ];

    public ?int $editingId = null;

    public bool $showConfirmHouseguestDeletionModal = false;

    public ?int $confirmingHouseguestDeletionId = null;

    public ?string $confirmingHouseguestDeletionName = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();
        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->avatar = null;
        $this->form = [
            'name' => '',
            'sex' => 'M',
            'occupations' => [],
            'avatar_url' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function edit(int $houseguestId): void
    {
        $houseguest = Houseguest::query()->findOrFail($houseguestId);

        $this->editingId = $houseguest->id;
        $this->avatar = null;
        $this->form = [
            'name' => $houseguest->name,
            'sex' => $houseguest->sex ?? 'M',
            'occupations' => is_array($houseguest->occupations) ? $houseguest->occupations : [],
            'avatar_url' => $houseguest->avatar_url,
            'is_active' => $houseguest->is_active,
            'sort_order' => $houseguest->sort_order,
        ];
    }

    public function confirmDelete(int $houseguestId): void
    {
        Gate::authorize('admin');

        $houseguest = Houseguest::query()->findOrFail($houseguestId);

        $this->confirmingHouseguestDeletionId = $houseguest->id;
        $this->confirmingHouseguestDeletionName = $houseguest->name;
        $this->showConfirmHouseguestDeletionModal = true;
    }

    public function deleteSelectedHouseguest(): void
    {
        Gate::authorize('admin');

        if ($this->confirmingHouseguestDeletionId === null) {
            return;
        }

        $houseguest = Houseguest::query()->findOrFail($this->confirmingHouseguestDeletionId);

        if ($houseguest->avatar_url) {
            Storage::disk('public')->delete($houseguest->avatar_url);
        }

        $houseguest->delete();

        app(BuildDashboardStats::class)->forget($this->season);

        if ($this->editingId === $houseguest->id) {
            $this->startCreate();
        }

        $this->confirmingHouseguestDeletionId = null;
        $this->confirmingHouseguestDeletionName = null;
        $this->showConfirmHouseguestDeletionModal = false;

        $this->refresh();

        $this->dispatch('houseguest-deleted');
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $request = new SaveHouseguestRequest();
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $houseguest = $this->editingId
            ? Houseguest::query()->findOrFail($this->editingId)
            : new Houseguest(['season_id' => $this->season->id]);

        if ($this->avatar) {
            if ($houseguest->avatar_url) {
                Storage::disk('public')->delete($houseguest->avatar_url);
            }

            $validated['form']['avatar_url'] = $this->avatar->store('houseguests/avatars', 'public');
            $this->avatar = null;
        }

        $houseguest->fill(array_merge($validated['form'], ['season_id' => $this->season->id]));
        $houseguest->save();

        app(BuildDashboardStats::class)->forget($this->season);

        $this->startCreate();
        $this->refresh();

        $this->dispatch('houseguest-saved');
    }

    private function refresh(): void
    {
        $this->houseguests = Houseguest::query()
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
};
