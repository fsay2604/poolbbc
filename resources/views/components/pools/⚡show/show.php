<?php

use App\Actions\Drafts\ChangeDraftStatus;
use App\Actions\Drafts\StartDraft;
use App\Actions\Pools\RemovePoolMember;
use App\Enums\DraftStatus;
use App\Models\Pool;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public bool $canManage = false;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool;
        $this->canManage = Gate::allows('update', $pool);
        $this->refreshPool();
    }

    public function startDraft(StartDraft $startDraft): void
    {
        Gate::authorize('update', $this->pool);
        $startDraft->handle($this->pool->draft, auth()->user());
        $this->refreshPool();
        $this->dispatch('draft-updated');
    }

    public function pauseDraft(ChangeDraftStatus $changeDraftStatus): void
    {
        Gate::authorize('update', $this->pool);
        $changeDraftStatus->handle($this->pool->draft, auth()->user(), DraftStatus::Paused);
        $this->refreshPool();
        $this->dispatch('draft-updated');
    }

    public function resumeDraft(ChangeDraftStatus $changeDraftStatus): void
    {
        Gate::authorize('update', $this->pool);
        $changeDraftStatus->handle($this->pool->draft, auth()->user(), DraftStatus::Active);
        $this->refreshPool();
        $this->dispatch('draft-updated');
    }

    public function removeMember(int $memberId, RemovePoolMember $removePoolMember): void
    {
        Gate::authorize('update', $this->pool);
        $member = $this->pool->members()->findOrFail($memberId);
        $removePoolMember->handle($this->pool, $member, auth()->user());
        $this->refreshPool();
        $this->dispatch('member-removed');
    }

    private function refreshPool(): void
    {
        $this->pool = $this->pool->fresh([
            'season',
            'owner',
            'draft.currentMember.user',
            'members' => fn ($query) => $query->where('status', 'active')->with('user')->orderBy('draft_position'),
        ])->loadCount(['rounds', 'events']);
    }
};
