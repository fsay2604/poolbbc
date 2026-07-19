<?php

use App\Actions\Drafts\CorrectDraftPick;
use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Pools\ListUserPools;
use App\Enums\DraftStatus;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Houseguest;
use App\Models\Pool;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public Draft $draft;

    public $availableHouseguests;

    public $members;

    public bool $canPick = false;

    public bool $canManage = false;

    public bool $canCorrect = false;

    public string $search = '';

    public string $sexFilter = '';

    public int $completedPicks = 0;

    public int $totalPicks = 0;

    public bool $showConfirmPickModal = false;

    public ?int $confirmingHouseguestId = null;

    public ?string $confirmingHouseguestName = null;

    public bool $showCorrectionModal = false;

    public ?int $correctingPickId = null;

    public ?int $correctionHouseguestId = null;

    public string $correctionReason = '';

    public $correctionHouseguests;

    public $availablePools;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        abort_unless($pool->usesDraft(), 403);
        $this->pool = $pool;
        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->refreshDraft();
    }

    public function updatedSearch(): void
    {
        $this->refreshDraft();
    }

    public function updatedSexFilter(): void
    {
        $this->refreshDraft();
    }

    public function confirmSelection(int $houseguestId): void
    {
        $houseguest = $this->availableHouseguests->firstWhere('id', $houseguestId);
        abort_if($houseguest === null, 404);

        $this->confirmingHouseguestId = $houseguest->id;
        $this->confirmingHouseguestName = $houseguest->name;
        $this->showConfirmPickModal = true;
    }

    public function pick(MakeDraftPick $makeDraftPick): void
    {
        Gate::authorize('pick', $this->draft);
        $member = $this->pool->memberFor(auth()->user());
        abort_if($member === null, 403);
        abort_if($this->confirmingHouseguestId === null, 422);

        $houseguest = Houseguest::query()
            ->where('season_id', $this->pool->season_id)
            ->findOrFail($this->confirmingHouseguestId);

        try {
            $makeDraftPick->handle($this->draft, $member, $houseguest);
        } catch (ValidationException $exception) {
            $this->refreshDraft();
            $this->addError('draft', collect($exception->errors())->flatten()->first());
            $this->showConfirmPickModal = false;

            return;
        }

        $this->showConfirmPickModal = false;
        $this->confirmingHouseguestId = null;
        $this->confirmingHouseguestName = null;
        $this->refreshDraft();
        $this->dispatch('draft-pick-made');
    }

    public function startCorrection(int $pickId): void
    {
        Gate::authorize('update', $this->pool);

        if (! $this->canCorrect) {
            $this->addError('draft', __('A completed draft is immutable.'));

            return;
        }

        $pick = $this->draft->picks->firstWhere('id', $pickId);
        abort_if($pick === null, 404);

        $this->correctingPickId = $pick->id;
        $this->correctionHouseguestId = null;
        $this->correctionReason = '';
        $this->resetErrorBag(['correctionHouseguestId', 'correctionReason']);
        $this->refreshCorrectionHouseguests();
        $this->showCorrectionModal = true;
    }

    public function correct(CorrectDraftPick $correctDraftPick): void
    {
        Gate::authorize('update', $this->pool);
        $validated = $this->validate([
            'correctionHouseguestId' => ['required', 'integer'],
            'correctionReason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $pick = DraftPick::query()->where('pool_id', $this->pool->id)->findOrFail($this->correctingPickId);
            $houseguest = Houseguest::query()->where('season_id', $this->pool->season_id)->findOrFail($validated['correctionHouseguestId']);
            $correctDraftPick->handle($pick, $houseguest, auth()->user(), $validated['correctionReason']);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $componentField = match ($field) {
                    'houseguest' => 'correctionHouseguestId',
                    'correction_reason' => 'correctionReason',
                    default => $field,
                };

                $this->addError($componentField, collect($messages)->first());
            }

            $this->refreshDraft();

            return;
        }

        $this->showCorrectionModal = false;
        $this->correctingPickId = null;
        $this->correctionHouseguestId = null;
        $this->correctionReason = '';
        $this->refreshDraft();
        $this->dispatch('draft-pick-corrected');
    }

    public function refreshDraft(): void
    {
        $this->pool = $this->pool->fresh(['season']);
        $this->draft = $this->pool->draft()->with([
            'currentMember.user',
            'picks.houseguest',
            'picks.poolMember.user',
        ])->firstOrFail();

        $selectedPicks = $this->pool->exclusive_draft
            ? $this->draft->picks
            : $this->draft->picks->where('pool_member_id', $this->draft->current_pool_member_id);
        $selectedHouseguestIds = $selectedPicks->pluck('houseguest_id');

        $this->availableHouseguests = $this->pool->season->houseguests()
            ->where('is_active', true)
            ->when($selectedHouseguestIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $selectedHouseguestIds))
            ->when(filled($this->search), fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->when(filled($this->sexFilter), fn ($query) => $query->where('sex', $this->sexFilter))
            ->orderBy('sort_order')
            ->get();
        $this->members = $this->pool->activeMembers()->with('user')->orderBy('draft_position')->get();
        $this->canPick = Gate::allows('pick', $this->draft);
        $this->canManage = Gate::allows('update', $this->pool);
        $this->canCorrect = $this->canManage && $this->draft->status !== DraftStatus::Completed;
        $this->completedPicks = $this->draft->picks->count();
        $this->totalPicks = $this->members->count() * $this->pool->picks_per_member;
        $this->refreshCorrectionHouseguests();
    }

    private function refreshCorrectionHouseguests(): void
    {
        $correctingPick = $this->draft->picks->firstWhere('id', $this->correctingPickId);
        $unavailableHouseguestIds = $this->draft->picks
            ->filter(fn (DraftPick $pick): bool => $this->pool->exclusive_draft
                || ($correctingPick !== null && $pick->pool_member_id === $correctingPick->pool_member_id))
            ->pluck('houseguest_id');

        $this->correctionHouseguests = $this->pool->season->houseguests()
            ->where('is_active', true)
            ->when($unavailableHouseguestIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $unavailableHouseguestIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
};
