<?php

use App\Actions\Pools\ListUserPools;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public ?PoolMember $member = null;

    /** @var array<int, int|list<int>|null> */
    public array $selections = [];

    /** @var list<string> */
    public array $missingPredictions = [];

    public int $submittedCount = 0;

    public int $totalCount = 0;

    public Collection $availablePools;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        abort_unless($pool->usesPredictions(), 404);

        $this->pool = $pool->load('season');
        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->member = $pool->memberFor(auth()->user());
        $this->refreshJourney();
    }

    public function updatedSelections(mixed $value, string $poolEventId): void
    {
        if (! ctype_digit($poolEventId)) {
            return;
        }

        $this->saveDraft((int) $poolEventId);
    }

    public function saveDraft(int $poolEventId): void
    {
        $this->resetErrorBag("selections.{$poolEventId}");

        try {
            $this->store($poolEventId, false);
            $this->refreshJourney();
            $this->dispatch('prediction-autosaved');
        } catch (ValidationException $exception) {
            $this->addError("selections.{$poolEventId}", collect($exception->errors())->flatten()->first());
        }
    }

    public function submit(int $poolEventId): void
    {
        $this->resetErrorBag("selections.{$poolEventId}");

        try {
            $this->store($poolEventId, true);
            $this->refreshJourney();
            $this->dispatch('prediction-submitted');
        } catch (ValidationException $exception) {
            $this->addError("selections.{$poolEventId}", collect($exception->errors())->flatten()->first());
        }
    }

    private function store(int $poolEventId, bool $submit): void
    {
        Gate::authorize('view', $this->pool->fresh());
        abort_if($this->member === null, 403);

        $poolEvent = PoolEvent::query()
            ->whereBelongsTo($this->pool)
            ->where('is_active', true)
            ->findOrFail($poolEventId);

        app(SubmitPoolEventPrediction::class)->handle(
            $poolEvent,
            $this->member,
            $this->selectionIds($poolEventId),
            $submit,
        );
    }

    /** @return list<int> */
    private function selectionIds(int $poolEventId): array
    {
        $selection = $this->selections[$poolEventId] ?? [];
        $values = is_array($selection) ? $selection : [$selection];

        return collect($values)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /** @return EloquentCollection<int, SeasonRound> */
    #[Computed]
    public function rounds(): EloquentCollection
    {
        $poolId = $this->pool->id;
        $memberId = $this->member?->id;
        $predictionModes = [EventMode::Prediction->value, EventMode::Hybrid->value];
        $rounds = SeasonRound::query()
            ->where('season_id', $this->pool->season_id)
            ->whereHas('events.poolEvents', fn ($query) => $query
                ->where('pool_id', $poolId)
                ->where('is_active', true)
                ->whereIn('mode', $predictionModes))
            ->with([
                'events' => fn ($query) => $query
                    ->whereHas('poolEvents', fn ($poolEventQuery) => $poolEventQuery
                        ->where('pool_id', $poolId)
                        ->where('is_active', true)
                        ->whereIn('mode', $predictionModes))
                    ->with(['options', 'latestResult.options']),
                'events.poolEvents' => fn ($query) => $query
                    ->where('pool_id', $poolId)
                    ->where('is_active', true)
                    ->whereIn('mode', $predictionModes)
                    ->with(['predictions' => fn ($predictionQuery) => $predictionQuery
                        ->when(
                            $memberId !== null,
                            fn ($memberQuery) => $memberQuery->where('pool_member_id', $memberId),
                            fn ($memberQuery) => $memberQuery->whereRaw('1 = 0'),
                        )
                        ->with(['options', 'poolMember.user'])]),
            ])
            ->orderBy('position')
            ->get();

        $visiblePoolEvents = $rounds->flatMap->events
            ->flatMap(function (SeasonEvent $event) {
                return $event->poolEvents
                    ->filter(fn (PoolEvent $poolEvent): bool => $this->competingPredictionsAreVisible($poolEvent, $event));
            })
            ->values();
        $visiblePoolEventIds = $visiblePoolEvents->pluck('id')->all();

        if ($visiblePoolEventIds !== []) {
            $visiblePredictions = PoolEventPrediction::query()
                ->whereIn('pool_event_id', $visiblePoolEventIds)
                ->where(function ($visiblePredictionQuery) use ($memberId): void {
                    if ($memberId !== null) {
                        $visiblePredictionQuery->where('pool_member_id', $memberId)
                            ->orWhereIn('status', [
                                PredictionStatus::Submitted->value,
                                PredictionStatus::Locked->value,
                            ]);

                        return;
                    }

                    $visiblePredictionQuery->whereIn('status', [
                        PredictionStatus::Submitted->value,
                        PredictionStatus::Locked->value,
                    ]);
                })
                ->with(['options', 'poolMember.user'])
                ->get()
                ->groupBy('pool_event_id');

            $visiblePoolEvents->each(function (PoolEvent $poolEvent) use ($visiblePredictions): void {
                $poolEvent->setRelation(
                    'predictions',
                    $visiblePredictions->get($poolEvent->id, new EloquentCollection),
                );
            });
        }

        return $rounds;
    }

    private function competingPredictionsAreVisible(PoolEvent $poolEvent, SeasonEvent $event): bool
    {
        $effectiveStatus = $event->effectiveStatus();

        if ($effectiveStatus === EventStatus::Published) {
            return true;
        }

        return $poolEvent->visibility === 'after_lock'
            && in_array($effectiveStatus, [EventStatus::Locked, EventStatus::ResultEntered], true);
    }

    private function refreshJourney(): void
    {
        $memberId = $this->member?->id;
        unset($this->rounds);
        $rounds = $this->rounds;

        $this->selections = [];
        $this->missingPredictions = [];
        $this->submittedCount = 0;
        $this->totalCount = 0;

        foreach ($rounds as $round) {
            foreach ($round->events as $event) {
                $poolEvent = $event->poolEvents->first();
                if ($poolEvent === null) {
                    continue;
                }

                $this->totalCount++;
                $prediction = $poolEvent->predictions->firstWhere('pool_member_id', $memberId);
                $selectedIds = $prediction?->options->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
                $this->selections[$poolEvent->id] = $poolEvent->prediction_max_selections === 1
                    ? ($selectedIds[0] ?? null)
                    : $selectedIds;

                if ($prediction !== null && in_array($prediction->status, [PredictionStatus::Submitted, PredictionStatus::Locked], true)) {
                    $this->submittedCount++;
                } elseif ($event->effectiveStatus()->value === 'open') {
                    $this->missingPredictions[] = $event->name;
                }
            }
        }
    }
};
