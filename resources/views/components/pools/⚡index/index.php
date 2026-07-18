<?php

use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Actions\Pools\PreviewPoolInvitation;
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

    public ?Pool $joinPreview = null;

    /** @var array<string, mixed> */
    public array $form = [
        'season_id' => '',
        'name' => '',
        'description' => '',
        'competition_mode' => 'hybrid',
        'use_scoring_overrides' => false,
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
        $this->form['scoring_config'] = Pool::defaultScoringConfig();
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
        $this->form['use_scoring_overrides'] = false;
        $this->form['scoring_config'] = Pool::defaultScoringConfig();
        $this->refreshPools();
        $this->dispatch('pool-created');
    }

    public function previewJoin(PreviewPoolInvitation $previewPoolInvitation): void
    {
        $request = new JoinPoolRequest;
        $validated = $this->validate($request->rules());
        $this->joinPreview = $previewPoolInvitation->handle(
            auth()->user(),
            $validated['joinForm']['invite_code'],
        );
    }

    public function join(JoinPool $joinPool): void
    {
        $request = new JoinPoolRequest;
        $validated = $this->validate($request->rules());
        $inviteCode = mb_strtoupper(trim($validated['joinForm']['invite_code']));
        if ($this->joinPreview === null || $this->joinPreview->invite_code !== $inviteCode) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'joinForm.invite_code' => 'Consultez les règles de cette invitation avant de l’accepter.',
            ]);
        }

        $joinPool->handle(auth()->user(), $inviteCode);

        $this->joinForm = ['invite_code' => ''];
        $this->joinPreview = null;
        $this->refreshPools();
        $this->dispatch('pool-joined');
    }

    public function updatedJoinFormInviteCode(): void
    {
        $this->joinPreview = null;
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
