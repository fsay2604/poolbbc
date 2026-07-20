<?php

use App\Actions\Pools\ListUserPools;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\SeasonEventResult;
use App\Support\PredictionEventView;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** @var array<string|int, int|list<int>|null> */
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

    public function updatedSelections(mixed $value, string $eventKey): void
    {
        $this->saveDraft($eventKey);
    }

    public function saveDraft(string|int $eventKey): void
    {
        $this->storePrediction($eventKey, false, 'prediction-autosaved');
    }

    public function submit(string|int $eventKey): void
    {
        $this->storePrediction($eventKey, true, 'prediction-submitted');
    }

    private function storePrediction(string|int $eventKey, bool $submit, string $eventName): void
    {
        $normalizedKey = $this->normalizedEventKeyOrNull($eventKey);
        $errorKey = $normalizedKey === null ? 'selections' : "selections.{$normalizedKey}";
        $this->resetErrorBag($errorKey);

        try {
            if ($normalizedKey === null) {
                throw ValidationException::withMessages([
                    'prediction' => __('This pool event does not accept predictions.'),
                ]);
            }

            $this->store($normalizedKey, $submit);
            $this->refreshJourney();
            $this->dispatch($eventName);
        } catch (AuthorizationException) {
            $this->addError($errorKey, __('Predictions are closed for this event.'));
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->addError($errorKey, is_string($message) ? $message : __('This pool event does not accept predictions.'));
        }
    }

    private function store(string $normalizedKey, bool $submit): void
    {
        $pool = $this->pool->fresh();
        if ($pool === null) {
            throw ValidationException::withMessages(['prediction' => __('This pool event does not accept predictions.')]);
        }

        Gate::authorize('view', $pool);
        $member = $pool->activeMembers()
            ->whereBelongsTo(auth()->user())
            ->first();
        if ($member === null) {
            throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this event.')]);
        }

        [$source, $sourceId] = $this->parseEventKey($normalizedKey);
        $selectionIds = $this->selectionIds($normalizedKey);

        if ($source === 'official') {
            $poolEvent = $pool->poolEvents()
                ->where('is_active', true)
                ->whereNotNull('season_event_id')
                ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
                ->whereHas('seasonEvent.round', fn ($query) => $query->where('season_id', $pool->season_id))
                ->find($sourceId);

            if ($poolEvent === null) {
                throw ValidationException::withMessages(['prediction' => __('This pool event does not accept predictions.')]);
            }

            app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, $selectionIds, $submit);

            return;
        }

        $event = $pool->events()
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->whereHas('round', fn ($query) => $query->where('pool_id', $pool->id))
            ->find($sourceId);

        if ($event === null || Gate::forUser(auth()->user())->denies('predict', $event)) {
            throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this event.')]);
        }

        app(SubmitEventPrediction::class)->handle($event, $member, $selectionIds, $submit);
    }

    /** @return array{string, int} */
    private function parseEventKey(string $eventKey): array
    {
        [$source, $sourceId] = explode('-', $eventKey, 2);

        return [$source, (int) $sourceId];
    }

    private function normalizedEventKeyOrNull(string|int $eventKey): ?string
    {
        $eventKey = (string) $eventKey;

        if (preg_match('/\A(?:official|local)-[1-9]\d*\z/', $eventKey) !== 1) {
            return null;
        }

        return $eventKey;
    }

    /** @return list<int> */
    private function selectionIds(string $normalizedKey): array
    {
        $selection = $this->selections[$normalizedKey] ?? null;
        $values = is_array($selection) ? $selection : [$selection];

        return collect($values)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, PredictionEventView> */
    #[Computed]
    public function predictionEvents(): Collection
    {
        $officialPoolEvents = $this->officialPoolEvents();
        $localEvents = $this->localEvents();

        $officialVisiblePredictions = $this->visibleOfficialPredictions($officialPoolEvents);
        $localVisiblePredictions = $this->visibleLocalPredictions($localEvents);

        return $officialPoolEvents
            ->map(fn (PoolEvent $poolEvent): PredictionEventView => $this->officialEventView(
                $poolEvent,
                $officialVisiblePredictions->get($poolEvent->id, new EloquentCollection),
            ))
            ->concat($localEvents->map(fn (Event $event): PredictionEventView => $this->localEventView(
                $event,
                $localVisiblePredictions->get($event->id, new EloquentCollection),
            )))
            ->sortBy(fn (PredictionEventView $event): string => sprintf(
                '%010d|%d|%s|%010d|%020d',
                $event->roundPosition,
                $event->source === 'official' ? 0 : 1,
                $event->roundKey,
                $event->eventPosition,
                $event->locksAt?->getTimestamp() ?? PHP_INT_MAX,
            ))
            ->values();
    }

    /** @return Collection<int, array{key: string, name: string, source_label: string, events: Collection<int, PredictionEventView>}> */
    #[Computed]
    public function predictionRounds(): Collection
    {
        return $this->predictionEvents
            ->groupBy(fn (PredictionEventView $event): string => $event->roundKey)
            ->map(function (Collection $events): array {
                /** @var PredictionEventView $firstEvent */
                $firstEvent = $events->first();

                return [
                    'key' => $firstEvent->roundKey,
                    'name' => $firstEvent->roundName,
                    'source_label' => $firstEvent->sourceLabel,
                    'events' => $events->values(),
                ];
            })
            ->values();
    }

    /** @return EloquentCollection<int, PoolEvent> */
    private function officialPoolEvents(): EloquentCollection
    {
        $memberId = $this->member?->id;

        return PoolEvent::query()
            ->select([
                'id', 'pool_id', 'season_event_id', 'mode', 'is_active', 'visibility',
                'prediction_min_selections', 'prediction_max_selections',
            ])
            ->whereBelongsTo($this->pool)
            ->where('is_active', true)
            ->whereNotNull('season_event_id')
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->whereHas('seasonEvent', fn ($query) => $query->where('status', '!=', EventStatus::Cancelled->value))
            ->whereHas('seasonEvent.round', fn ($query) => $query->where('season_id', $this->pool->season_id))
            ->with([
                'seasonEvent:id,season_round_id,name,question,status,position,opens_at,locks_at',
                'seasonEvent.round:id,season_id,name,position',
                'seasonEvent.options:id,season_event_id,label,position',
                'seasonEvent.latestResult.options:id,label',
                'predictions' => fn ($query) => $query
                    ->select(['id', 'pool_event_id', 'pool_member_id', 'status', 'submitted_at', 'locked_at'])
                    ->when(
                        $memberId !== null,
                        fn ($memberQuery) => $memberQuery->where('pool_member_id', $memberId),
                        fn ($memberQuery) => $memberQuery->whereNull('id'),
                    )
                    ->with('options:id,label'),
            ])
            ->get();
    }

    /** @return EloquentCollection<int, Event> */
    private function localEvents(): EloquentCollection
    {
        $memberId = $this->member?->id;

        return Event::query()
            ->select([
                'id', 'pool_id', 'round_id', 'name', 'question', 'mode', 'status', 'position',
                'opens_at', 'locks_at', 'prediction_min_selections', 'prediction_max_selections',
            ])
            ->whereBelongsTo($this->pool)
            ->whereIn('mode', [EventMode::Prediction->value, EventMode::Hybrid->value])
            ->where('status', '!=', EventStatus::Cancelled->value)
            ->whereHas('round', fn ($query) => $query->where('pool_id', $this->pool->id))
            ->with([
                'round:id,pool_id,name,position',
                'options:id,event_id,label,position',
                'latestResult.options:id,label',
                'predictions' => fn ($query) => $query
                    ->select(['id', 'event_id', 'pool_member_id', 'status', 'submitted_at', 'locked_at'])
                    ->when(
                        $memberId !== null,
                        fn ($memberQuery) => $memberQuery->where('pool_member_id', $memberId),
                        fn ($memberQuery) => $memberQuery->whereNull('id'),
                    )
                    ->with('options:id,label'),
            ])
            ->get();
    }

    /**
     * @param  EloquentCollection<int, PoolEvent>  $poolEvents
     * @return Collection<int, EloquentCollection<int, PoolEventPrediction>>
     */
    private function visibleOfficialPredictions(EloquentCollection $poolEvents): Collection
    {
        $visiblePoolEventIds = $poolEvents
            ->filter(fn (PoolEvent $poolEvent): bool => $this->officialPredictionsAreVisible(
                $poolEvent,
                $poolEvent->seasonEvent->effectiveStatus(),
            ))
            ->pluck('id')
            ->all();

        if ($visiblePoolEventIds === []) {
            return collect();
        }

        return PoolEventPrediction::query()
            ->select(['id', 'pool_event_id', 'pool_member_id', 'status'])
            ->whereIn('pool_event_id', $visiblePoolEventIds)
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->with(['options:id,label', 'poolMember.user:id,name'])
            ->get()
            ->groupBy('pool_event_id');
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     * @return Collection<int, EloquentCollection<int, EventPrediction>>
     */
    private function visibleLocalPredictions(EloquentCollection $events): Collection
    {
        $visibleEventIds = $events
            ->filter(fn (Event $event): bool => $this->localPredictionsAreVisible($event->effectiveStatus()))
            ->pluck('id')
            ->all();

        if ($visibleEventIds === []) {
            return collect();
        }

        return EventPrediction::query()
            ->select(['id', 'event_id', 'pool_member_id', 'status'])
            ->whereIn('event_id', $visibleEventIds)
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->with(['options:id,label', 'poolMember.user:id,name'])
            ->get()
            ->groupBy('event_id');
    }

    /** @param  EloquentCollection<int, PoolEventPrediction>  $visiblePredictions */
    private function officialEventView(PoolEvent $poolEvent, EloquentCollection $visiblePredictions): PredictionEventView
    {
        $event = $poolEvent->seasonEvent;
        $prediction = $poolEvent->predictions->first();

        return $this->eventView(
            key: 'official-'.$poolEvent->id,
            source: 'official',
            sourceLabel: 'Saison officielle',
            sourceId: $poolEvent->id,
            roundKey: 'official-'.$event->round->id,
            roundName: $event->round->name,
            roundPosition: (int) $event->round->position,
            eventPosition: (int) $event->position,
            name: $event->name,
            question: $event->question,
            mode: $poolEvent->mode,
            effectiveStatus: $event->effectiveStatus(),
            locksAt: $event->locks_at,
            minimumSelections: (int) $poolEvent->prediction_min_selections,
            maximumSelections: (int) $poolEvent->prediction_max_selections,
            options: $event->options,
            prediction: $prediction,
            latestResult: $event->latestResult,
            visiblePredictions: $visiblePredictions,
            resultLabel: 'Résultat officiel',
        );
    }

    /** @param  EloquentCollection<int, EventPrediction>  $visiblePredictions */
    private function localEventView(Event $event, EloquentCollection $visiblePredictions): PredictionEventView
    {
        return $this->eventView(
            key: 'local-'.$event->id,
            source: 'local',
            sourceLabel: 'Propre au pool',
            sourceId: $event->id,
            roundKey: 'local-'.$event->round->id,
            roundName: $event->round->name,
            roundPosition: (int) $event->round->position,
            eventPosition: (int) $event->position,
            name: $event->name,
            question: $event->question,
            mode: $event->mode,
            effectiveStatus: $event->effectiveStatus(),
            locksAt: $event->locks_at,
            minimumSelections: (int) $event->prediction_min_selections,
            maximumSelections: (int) $event->prediction_max_selections,
            options: $event->options,
            prediction: $event->predictions->first(),
            latestResult: $event->latestResult,
            visiblePredictions: $visiblePredictions,
            resultLabel: 'Résultat du pool',
        );
    }

    private function eventView(
        string $key,
        string $source,
        string $sourceLabel,
        int $sourceId,
        string $roundKey,
        string $roundName,
        int $roundPosition,
        int $eventPosition,
        string $name,
        ?string $question,
        EventMode $mode,
        EventStatus $effectiveStatus,
        ?CarbonInterface $locksAt,
        int $minimumSelections,
        int $maximumSelections,
        EloquentCollection $options,
        EventPrediction|PoolEventPrediction|null $prediction,
        EventResult|SeasonEventResult|null $latestResult,
        EloquentCollection $visiblePredictions,
        string $resultLabel,
    ): PredictionEventView {
        [$progressState, $progressLabel, $progressColor] = $this->predictionProgress($prediction?->status, $effectiveStatus);
        $selectedOptionIds = $prediction?->options
            ->pluck('id')
            ->map(fn ($optionId): int => (int) $optionId)
            ->values()
            ->all() ?? [];
        $isOpen = $effectiveStatus === EventStatus::Open && $this->member !== null;
        $immutableLocksAt = $locksAt?->toImmutable();

        return new PredictionEventView(
            key: $key,
            source: $source,
            sourceLabel: $sourceLabel,
            sourceId: $sourceId,
            roundKey: $roundKey,
            roundName: $roundName,
            roundPosition: $roundPosition,
            eventPosition: $eventPosition,
            name: $name,
            question: $question,
            mode: $mode,
            effectiveStatus: $effectiveStatus,
            effectiveStatusLabel: $effectiveStatus->label(),
            locksAt: $immutableLocksAt,
            deadlineLabel: $immutableLocksAt?->setTimezone($this->pool->timezone)->format('d/m H:i'),
            minimumSelections: $minimumSelections,
            maximumSelections: $maximumSelections,
            selectionLimitLabel: $minimumSelections === $maximumSelections
                ? "{$minimumSelections} choix"
                : "{$minimumSelections} à {$maximumSelections} choix",
            options: $options->map(fn ($option): array => [
                'id' => (int) $option->id,
                'label' => $option->label,
            ])->values()->all(),
            selectedOptionIds: $selectedOptionIds,
            predictionStatus: $prediction?->status,
            progressState: $progressState,
            progressLabel: $progressLabel,
            progressColor: $progressColor,
            isSingleSelection: $maximumSelections === 1,
            canSave: $isOpen,
            canSubmit: $isOpen,
            submitLabel: $prediction !== null && in_array($prediction->status, [PredictionStatus::Submitted, PredictionStatus::Locked], true)
                ? 'Mettre à jour'
                : 'Soumettre',
            showResult: in_array($effectiveStatus, [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published], true),
            resultLabel: $resultLabel,
            hasPublishedResult: $latestResult !== null,
            resultOptionLabels: $latestResult?->options->pluck('label')->values()->all() ?? [],
            revealedPredictions: $visiblePredictions
                ->sortBy(fn ($revealedPrediction): string => $revealedPrediction->poolMember->user->name)
                ->map(fn ($revealedPrediction): array => [
                    'member_name' => $revealedPrediction->poolMember->user->name,
                    'option_labels' => $revealedPrediction->options->pluck('label')->values()->all(),
                ])
                ->values()
                ->all(),
        );
    }

    /** @return array{string, string, string} */
    private function predictionProgress(?PredictionStatus $predictionStatus, EventStatus $eventStatus): array
    {
        if ($eventStatus === EventStatus::Cancelled) {
            return ['expired', 'Annulée', 'zinc'];
        }

        if ($predictionStatus === PredictionStatus::Draft) {
            return $eventStatus === EventStatus::Open
                ? ['draft', 'Brouillon', 'amber']
                : ['expired', 'Brouillon expiré', 'red'];
        }

        if (in_array($predictionStatus, [PredictionStatus::Submitted, PredictionStatus::Locked], true)) {
            return $eventStatus === EventStatus::Open
                ? ['submitted', 'Soumise', 'green']
                : ['locked', 'Verrouillée', 'zinc'];
        }

        return $eventStatus === EventStatus::Open
            ? ['to_answer', 'À faire', 'amber']
            : ['missed', 'Non soumise', 'red'];
    }

    private function officialPredictionsAreVisible(PoolEvent $poolEvent, EventStatus $eventStatus): bool
    {
        if ($eventStatus === EventStatus::Published) {
            return true;
        }

        return $poolEvent->visibility === 'after_lock'
            && in_array($eventStatus, [EventStatus::Locked, EventStatus::ResultEntered], true);
    }

    private function localPredictionsAreVisible(EventStatus $eventStatus): bool
    {
        return in_array($eventStatus, [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published], true);
    }

    private function refreshJourney(): void
    {
        unset($this->predictionEvents, $this->predictionRounds);
        $events = $this->predictionEvents;

        $this->selections = [];
        $this->missingPredictions = [];
        $this->submittedCount = 0;
        $this->totalCount = $events->count();

        foreach ($events as $event) {
            $this->selections[$event->key] = $event->isSingleSelection
                ? ($event->selectedOptionIds[0] ?? null)
                : $event->selectedOptionIds;

            if (in_array($event->progressState, ['submitted', 'locked'], true)) {
                $this->submittedCount++;
            } elseif (in_array($event->progressState, ['to_answer', 'draft'], true)) {
                $this->missingPredictions[] = $event->name;
            }
        }
    }
};
