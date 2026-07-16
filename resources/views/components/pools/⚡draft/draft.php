<?php

use App\Actions\Drafts\MakeDraftPick;
use App\Models\Draft;
use App\Models\Houseguest;
use App\Models\Pool;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public Draft $draft;

    public $availableHouseguests;

    public $members;

    public bool $canPick = false;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool;
        $this->refreshDraft();
    }

    public function pick(int $houseguestId, MakeDraftPick $makeDraftPick): void
    {
        Gate::authorize('pick', $this->draft);
        $member = $this->pool->memberFor(auth()->user());
        abort_if($member === null, 403);

        $houseguest = Houseguest::query()
            ->where('season_id', $this->pool->season_id)
            ->findOrFail($houseguestId);

        $makeDraftPick->handle($this->draft, $member, $houseguest);
        $this->refreshDraft();
        $this->dispatch('draft-pick-made');
    }

    private function refreshDraft(): void
    {
        $this->pool = $this->pool->fresh(['season']);
        $this->draft = $this->pool->draft()->with([
            'currentMember.user',
            'picks.houseguest',
            'picks.poolMember.user',
        ])->firstOrFail();

        $selectedHouseguestIds = $this->pool->exclusive_draft
            ? $this->draft->picks->pluck('houseguest_id')
            : collect();

        $this->availableHouseguests = $this->pool->season->houseguests()
            ->where('is_active', true)
            ->when($selectedHouseguestIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $selectedHouseguestIds))
            ->orderBy('sort_order')
            ->get();
        $this->members = $this->pool->activeMembers()->with('user')->orderBy('draft_position')->get();
        $this->canPick = Gate::allows('pick', $this->draft);
    }
};
