<?php

use App\Actions\Drafts\ChangeDraftStatus;
use App\Actions\Drafts\SetDraftOrder;
use App\Actions\Drafts\StartDraft;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Pools\ActivatePool;
use App\Actions\Pools\ListUserPools;
use App\Actions\Pools\OpenPoolRegistration;
use App\Actions\Pools\RemovePoolMember;
use App\Actions\Pools\TransitionPoolStatus;
use App\Enums\DraftStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolStatus;
use App\Enums\PredictionStatus;
use App\Models\Pool;
use App\Models\PoolEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public bool $canManage = false;

    public bool $canManageLifecycle = false;

    public bool $showStartDraftModal = false;

    public bool $showRemoveMemberModal = false;

    public bool $isLeavingPool = false;

    public ?int $removingMemberId = null;

    public string $memberRemovalReason = '';

    /** @var array{rank:?int,total_points:int,predictions_submitted:int,predictions_total:int,next_deadline:?string,next_event:?string} */
    public array $summary = [];

    public Collection $recentPoints;

    public Collection $availablePools;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool;
        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->canManage = Gate::allows('update', $pool);
        $this->refreshPool();
    }

    public function startDraft(StartDraft $startDraft): void
    {
        Gate::authorize('update', $this->pool);
        $startDraft->handle($this->pool->draft, auth()->user());
        $this->showStartDraftModal = false;
        $this->refreshPool();
        $this->dispatch('draft-updated');
    }

    public function confirmStartDraft(): void
    {
        Gate::authorize('update', $this->pool);
        $this->showStartDraftModal = true;
    }

    public function randomizeDraftOrder(SetDraftOrder $setDraftOrder): void
    {
        Gate::authorize('update', $this->pool);
        $memberIds = $this->pool->activeMembers()->pluck('id')->shuffle()->values()->all();
        $setDraftOrder->handle($this->pool->draft, auth()->user(), $memberIds);
        $this->refreshPool();
        $this->dispatch('draft-order-updated');
    }

    public function moveDraftMember(int $memberId, int $direction, SetDraftOrder $setDraftOrder): void
    {
        Gate::authorize('update', $this->pool);
        $memberIds = $this->pool->activeMembers()->orderBy('draft_position')->pluck('id')->values();
        $index = $memberIds->search($memberId);
        $targetIndex = $index === false ? -1 : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $targetIndex < 0 || $targetIndex >= $memberIds->count()) {
            return;
        }

        $movedMemberId = $memberIds[$index];
        $memberIds[$index] = $memberIds[$targetIndex];
        $memberIds[$targetIndex] = $movedMemberId;
        $setDraftOrder->handle($this->pool->draft, auth()->user(), $memberIds->all());
        $this->refreshPool();
        $this->dispatch('draft-order-updated');
    }

    public function activate(ActivatePool $activatePool): void
    {
        Gate::authorize('update', $this->pool);
        $activatePool->handle($this->pool, auth()->user());
        $this->refreshPool();
        $this->dispatch('pool-activated');
    }

    public function openRegistrations(OpenPoolRegistration $openPoolRegistration): void
    {
        Gate::authorize('update', $this->pool);
        $openPoolRegistration->handle($this->pool, auth()->user());
        $this->refreshPool();
        $this->dispatch('pool-registrations-opened');
    }

    public function completePool(TransitionPoolStatus $transitionPoolStatus): void
    {
        Gate::authorize('update', $this->pool);
        $transitionPoolStatus->handle($this->pool, auth()->user(), PoolStatus::Completed);
        $this->refreshPool();
        $this->dispatch('pool-completed');
    }

    public function archivePool(TransitionPoolStatus $transitionPoolStatus): void
    {
        Gate::authorize('archive', $this->pool);
        $transitionPoolStatus->handle($this->pool, auth()->user(), PoolStatus::Archived);
        $this->refreshPool();
        $this->dispatch('pool-archived');
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

    public function startMemberRemoval(int $memberId): void
    {
        $member = $this->pool->members()->findOrFail($memberId);
        $this->isLeavingPool = $member->user_id === auth()->id();
        Gate::authorize($this->isLeavingPool ? 'leave' : 'remove', $member);

        $this->removingMemberId = $member->id;
        $this->memberRemovalReason = '';
        $this->resetValidation('memberRemovalReason');
        $this->showRemoveMemberModal = true;
    }

    public function cancelMemberRemoval(): void
    {
        $this->showRemoveMemberModal = false;
        $this->isLeavingPool = false;
        $this->removingMemberId = null;
        $this->memberRemovalReason = '';
        $this->resetValidation('memberRemovalReason');
    }

    public function removeMember(RemovePoolMember $removePoolMember): void
    {
        $member = $this->pool->members()->findOrFail($this->removingMemberId);
        $isLeavingPool = $member->user_id === auth()->id();
        Gate::authorize($isLeavingPool ? 'leave' : 'remove', $member);

        $this->memberRemovalReason = trim($this->memberRemovalReason);
        $validated = $this->validate(
            ['memberRemovalReason' => ['required', 'string', 'min:3', 'max:1000']],
            [
                'memberRemovalReason.required' => 'La raison du départ est obligatoire.',
                'memberRemovalReason.min' => 'La raison doit contenir au moins 3 caractères.',
                'memberRemovalReason.max' => 'La raison ne peut pas dépasser 1000 caractères.',
            ],
        );

        $removePoolMember->handle($this->pool, $member, auth()->user(), $validated['memberRemovalReason']);
        $this->cancelMemberRemoval();

        if ($isLeavingPool) {
            $this->redirectRoute('pools.index', navigate: true);

            return;
        }

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
        ]);
        $this->loadUnifiedEventCounts();
        $this->canManage = Gate::allows('update', $this->pool);
        $this->canManageLifecycle = $this->pool->isManagedBy(auth()->user());
        $isTerminal = $this->pool->isReadOnly();

        $member = $this->pool->memberFor(auth()->user());
        $leaderboardRow = app(BuildPoolLeaderboard::class)
            ->handle($this->pool)
            ->first(fn (array $row): bool => $row['member']->user_id === auth()->id());
        $predictionQuery = $this->pool->poolEvents()
            ->where('is_active', true)
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->whereNotNull('season_event_id');
        $totalPredictions = (clone $predictionQuery)->count();
        $submittedPredictions = $member === null ? 0 : $member->poolEventPredictions()
            ->whereHas('poolEvent', fn ($query) => $query
                ->where('pool_id', $this->pool->id)
                ->where('is_active', true)
                ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value]))
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->count();
        $nextPoolEvent = $isTerminal ? null : (clone $predictionQuery)
            ->whereHas('seasonEvent', fn ($query) => $query
                ->where('locks_at', '>', now())
                ->whereNotIn('status', [EventStatus::Published->value, EventStatus::Cancelled->value]))
            ->with('seasonEvent')
            ->get()
            ->sortBy('seasonEvent.locks_at')
            ->first();

        $this->summary = [
            'rank' => $leaderboardRow['rank'] ?? null,
            'total_points' => $leaderboardRow['total_points'] ?? 0,
            'predictions_submitted' => $submittedPredictions,
            'predictions_total' => $totalPredictions,
            'next_deadline' => $nextPoolEvent?->seasonEvent?->locks_at?->timezone($this->pool->timezone)->format('d/m H:i'),
            'next_event' => $nextPoolEvent?->seasonEvent?->name,
        ];

        $this->recentPoints = $member?->pointEntries()
            ->published()
            ->with(['event', 'poolEvent.seasonEvent'])
            ->latest()
            ->limit(5)
            ->get() ?? collect();
    }

    private function loadUnifiedEventCounts(): void
    {
        $poolEvents = $this->pool->poolEvents()
            ->where('is_active', true)
            ->with([
                'seasonEvent:id,season_round_id',
                'localEvent:id,round_id',
            ])
            ->get(['id', 'season_event_id', 'local_event_id']);
        $roundCount = $poolEvents
            ->map(function (PoolEvent $poolEvent): ?string {
                if ($poolEvent->season_event_id !== null) {
                    return $poolEvent->seasonEvent === null
                        ? null
                        : "official:{$poolEvent->seasonEvent->season_round_id}";
                }

                return $poolEvent->localEvent === null
                    ? null
                    : "local:{$poolEvent->localEvent->round_id}";
            })
            ->filter()
            ->unique()
            ->count();

        $this->pool->setAttribute('rounds_count', $roundCount);
        $this->pool->setAttribute('events_count', $poolEvents->count());
    }
};
