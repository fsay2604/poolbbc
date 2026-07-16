<?php

use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Http\Requests\Pools\CreatePoolRequest;
use App\Http\Requests\Pools\JoinPoolRequest;
use App\Models\Pool;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public $pools;

    public $seasons;

    /** @var array<string, mixed> */
    public array $form = [
        'season_id' => '',
        'name' => '',
        'description' => '',
        'timezone' => 'America/Toronto',
        'max_members' => 12,
        'picks_per_member' => 1,
        'draft_mode' => 'snake',
        'exclusive_draft' => true,
    ];

    /** @var array{invite_code:string} */
    public array $joinForm = ['invite_code' => ''];

    public function mount(): void
    {
        Gate::authorize('viewAny', Pool::class);
        $this->seasons = Season::query()->orderByDesc('is_active')->latest()->get();
        $this->refreshPools();
    }

    public function create(CreatePool $createPool): void
    {
        Gate::authorize('create', Pool::class);
        $request = new CreatePoolRequest;
        $validated = $this->validate($request->rules());

        $createPool->handle(auth()->user(), $validated['form']);
        $this->reset('form.name', 'form.description');
        $this->refreshPools();
        $this->dispatch('pool-created');
    }

    public function join(JoinPool $joinPool): void
    {
        $request = new JoinPoolRequest;
        $validated = $this->validate($request->rules());
        $joinPool->handle(auth()->user(), $validated['joinForm']['invite_code']);

        $this->joinForm = ['invite_code' => ''];
        $this->refreshPools();
        $this->dispatch('pool-joined');
    }

    private function refreshPools(): void
    {
        $this->pools = Pool::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', auth()->id())->where('status', 'active'))
            ->with(['season', 'draft.currentMember.user'])
            ->withCount('activeMembers')
            ->latest()
            ->get();
    }
};
