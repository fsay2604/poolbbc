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
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        $officialPredictionQuery = $this->officialPredictionEventsQuery();
        $localPredictionQuery = $this->localPredictionEventsQuery();
        $totalPredictions = (clone $officialPredictionQuery)->count() + (clone $localPredictionQuery)->count();
        $submittedOfficialPredictions = $member === null ? 0 : PoolEventPrediction::query()
            ->whereBelongsTo($member)
            ->whereIn('pool_event_id', (clone $officialPredictionQuery)->select('id'))
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->count();
        $submittedLocalPredictions = $member === null ? 0 : EventPrediction::query()
            ->whereBelongsTo($member)
            ->whereIn('event_id', (clone $localPredictionQuery)->select('id'))
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->count();
        $now = now();
        $nextOfficialPoolEvent = $isTerminal ? null : (clone $officialPredictionQuery)
            ->whereHas('seasonEvent', fn (Builder $query): Builder => $this->applyRespondableDeadlineScope($query, $now))
            ->with('seasonEvent')
            ->get()
            ->sortBy('seasonEvent.locks_at')
            ->first();
        $nextLocalEvent = $isTerminal ? null : $this->applyRespondableDeadlineScope(clone $localPredictionQuery, $now)
            ->orderBy('locks_at')
            ->first();
        $nextPrediction = collect([
            $nextOfficialPoolEvent === null ? null : [
                'deadline' => $nextOfficialPoolEvent->seasonEvent->locks_at,
                'name' => $nextOfficialPoolEvent->seasonEvent->name,
            ],
            $nextLocalEvent === null ? null : [
                'deadline' => $nextLocalEvent->locks_at,
                'name' => $nextLocalEvent->name,
            ],
        ])->filter()->sortBy('deadline')->first();

        $this->summary = [
            'rank' => $leaderboardRow['rank'] ?? null,
            'total_points' => $leaderboardRow['total_points'] ?? 0,
            'predictions_submitted' => $submittedOfficialPredictions + $submittedLocalPredictions,
            'predictions_total' => $totalPredictions,
            'next_deadline' => data_get($nextPrediction, 'deadline')?->timezone($this->pool->timezone)->format('d/m H:i'),
            'next_event' => data_get($nextPrediction, 'name'),
        ];

        $this->recentPoints = $member?->pointEntries()
            ->published()
            ->with(['event', 'poolEvent.seasonEvent'])
            ->latest()
            ->limit(5)
            ->get() ?? collect();
    }

    /** @return Builder<PoolEvent> */
    private function officialPredictionEventsQuery(): Builder
    {
        return PoolEvent::query()
            ->whereBelongsTo($this->pool)
            ->where('is_active', true)
            ->whereNotNull('season_event_id')
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->whereHas('seasonEvent', fn (Builder $query): Builder => $query
                ->where('status', '!=', EventStatus::Cancelled->value))
            ->whereHas('seasonEvent.round', fn (Builder $query): Builder => $query
                ->where('season_id', $this->pool->season_id));
    }

    /** @return Builder<Event> */
    private function localPredictionEventsQuery(): Builder
    {
        return Event::query()
            ->whereBelongsTo($this->pool)
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->where('status', '!=', EventStatus::Cancelled->value)
            ->whereHas('round', fn (Builder $query): Builder => $query
                ->where('pool_id', $this->pool->id));
    }

    private function applyRespondableDeadlineScope(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->whereNotNull('locks_at')
            ->where('locks_at', '>', $now)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where(function (Builder $query) use ($now): void {
                        $query
                            ->where('status', EventStatus::Open->value)
                            ->where(function (Builder $query) use ($now): void {
                                $query->whereNull('opens_at')->orWhere('opens_at', '<=', $now);
                            });
                    })
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query
                            ->where('status', EventStatus::Draft->value)
                            ->whereNotNull('opens_at')
                            ->where('opens_at', '<=', $now);
                    });
            });
    }

    private function loadUnifiedEventCounts(): void
    {
        $officialPoolEvents = $this->pool->poolEvents()
            ->where('is_active', true)
            ->whereNotNull('season_event_id')
            ->with('seasonEvent:id,season_round_id')
            ->get(['id', 'season_event_id']);
        $officialRoundCount = $officialPoolEvents
            ->pluck('seasonEvent.season_round_id')
            ->filter()
            ->unique()
            ->count();
        $localEventCount = $this->pool->events()->count();
        $localRoundCount = $this->pool->rounds()->whereHas('events')->count();

        $this->pool->setAttribute('rounds_count', $officialRoundCount + $localRoundCount);
        $this->pool->setAttribute('events_count', $officialPoolEvents->count() + $localEventCount);
    }
};
