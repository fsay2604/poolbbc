<?php

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\ReorderRoundEvents;
use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Events\UpdatePoolEventRules;
use App\Actions\Pools\ListUserPools;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Actions\Scoring\PreviewEventScore;
use App\Actions\Scoring\PublishEventResult;
use App\Enums\EventStatus;
use App\Http\Requests\Events\CreateEventRequest;
use App\Http\Requests\Pools\CreateRoundRequest;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventType;
use App\Models\Pool;
use App\Models\PoolEventPrediction;
use App\Models\Round;
use App\Models\SeasonRound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public $eventTypes;

    public $availablePools;

    public $activeMembers;

    /** @var array<string, mixed> */
    public array $roundForm = ['name' => '', 'starts_at' => null, 'ends_at' => null];

    /** @var array<string, mixed> */
    public array $eventForm = [
        'round_id' => '', 'event_type_id' => null, 'name' => '', 'question' => '',
        'mode' => 'prediction', 'answer_source' => 'houseguests', 'opens_at' => null, 'locks_at' => null,
        'prediction_min_selections' => 1, 'prediction_max_selections' => 1,
        'result_min_selections' => 1, 'result_max_selections' => 1,
        'result_publication_mode' => 'immediate',
        'owner_points' => 5, 'prediction_points' => 2, 'exact_bonus' => 0,
        'wrong_penalty' => 0, 'allow_negative' => false, 'allow_none' => false,
        'include_inactive_houseguests' => false,
        'save_as_template' => false,
    ];

    public string $customOptionsText = '';

    /** @var array<int, list<int>> */
    public array $predictionSelections = [];

    /** @var array<int, list<int>> */
    public array $resultSelections = [];

    /** @var array<int, string> */
    public array $correctionReasons = [];

    /** @var array<int, array<int, array{name:string,points:int}>> */
    public array $scorePreviews = [];

    /** @var array<int, string> */
    public array $resultPreviewFingerprints = [];

    /** @var list<int> */
    public array $editingDraftResultIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $officialRuleForms = [];

    /** @var array<int, list<int>> */
    public array $localRespondedMemberIds = [];

    /** @var array<int, list<int>> */
    public array $officialRespondedMemberIds = [];

    /** @var list<int> */
    public array $reorderableRoundIds = [];

    public bool $showCancellationModal = false;

    public bool $showRoundModal = false;

    public bool $showEventModal = false;

    public ?int $cancellingEventId = null;

    public string $cancellationReason = '';

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool->load('season');
        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->refreshEventTypes();
        $this->eventForm['locks_at'] = now()->addDay()->format('Y-m-d\TH:i');
        $this->refreshRounds();
    }

    public function createRound(RecordAuditLog $recordAuditLog): void
    {
        Gate::authorize('update', $this->pool);
        $request = new CreateRoundRequest;
        $validated = $this->validate($request->rules());
        $round = $this->pool->rounds()->create([
            ...$validated['roundForm'],
            'position' => ((int) $this->pool->rounds()->max('position')) + 1,
            'status' => 'draft',
        ]);
        $recordAuditLog->handle($this->pool, auth()->user(), 'round.created', $round);
        $this->roundForm = ['name' => '', 'starts_at' => null, 'ends_at' => null];
        $this->showRoundModal = false;
        $this->refreshRounds();
        $this->dispatch('round-created');
    }

    public function updatedEventFormEventTypeId(mixed $eventTypeId): void
    {
        if (blank($eventTypeId)) {
            return;
        }

        $eventType = EventType::query()->find($eventTypeId);
        if ($eventType === null || ($eventType->pool_id !== null && $eventType->pool_id !== $this->pool->id)) {
            return;
        }

        $this->eventForm['name'] = $eventType->name;
        $this->eventForm['mode'] = $eventType->default_mode->value;
        $this->eventForm['answer_source'] = $eventType->answer_source->value;
        $this->eventForm['owner_points'] = (int) data_get($eventType->default_config, 'owner.points_per_match', 5);
        $this->eventForm['prediction_points'] = (int) data_get($eventType->default_config, 'prediction.points_per_correct', 2);
        $this->eventForm['exact_bonus'] = (int) data_get($eventType->default_config, 'prediction.exact_match_bonus', 0);
        $this->eventForm['wrong_penalty'] = (int) data_get($eventType->default_config, 'prediction.wrong_answer_penalty', 0);
        $this->eventForm['allow_negative'] = (bool) data_get($eventType->default_config, 'allow_negative', false);
        $this->eventForm['question'] = (string) data_get($eventType->default_config, 'question', '');
        $this->eventForm['prediction_min_selections'] = (int) data_get($eventType->default_config, 'prediction_min_selections', 1);
        $this->eventForm['prediction_max_selections'] = (int) data_get($eventType->default_config, 'prediction_max_selections', 1);
        $this->eventForm['result_min_selections'] = (int) data_get($eventType->default_config, 'result_min_selections', 1);
        $this->eventForm['result_max_selections'] = (int) data_get($eventType->default_config, 'result_max_selections', 1);
        $this->eventForm['result_publication_mode'] = (string) data_get($eventType->default_config, 'result_publication_mode', 'immediate');
        $this->eventForm['allow_none'] = (bool) data_get($eventType->default_config, 'allow_none', false);
        $this->eventForm['include_inactive_houseguests'] = (bool) data_get($eventType->default_config, 'include_inactive_houseguests', false);
        $this->customOptionsText = collect(data_get($eventType->default_config, 'custom_options', []))->implode("\n");
    }

    public function createEvent(CreateEvent $createEvent): void
    {
        Gate::authorize('update', $this->pool);
        $request = new CreateEventRequest;
        $validated = $this->validate($request->rules());
        $form = $validated['eventForm'];

        if ($form['event_type_id'] !== null) {
            $eventType = EventType::query()->findOrFail($form['event_type_id']);
            abort_unless($eventType->pool_id === null || $eventType->pool_id === $this->pool->id, 403);
        }

        $data = Arr::except($form, ['owner_points', 'prediction_points', 'exact_bonus', 'wrong_penalty', 'allow_negative']);
        $data['scoring_config'] = [
            'owner' => ['points_per_match' => (int) $form['owner_points']],
            'prediction' => [
                'points_per_correct' => (int) $form['prediction_points'],
                'exact_match_bonus' => (int) $form['exact_bonus'],
                'wrong_answer_penalty' => (int) $form['wrong_penalty'],
            ],
            'allow_negative' => (bool) $form['allow_negative'],
        ];
        $data['custom_options'] = Str::of($this->customOptionsText)->explode("\n")
            ->map(fn ($label) => trim($label))->filter()->unique()->values()->all();

        $createEvent->handle($this->pool, auth()->user(), $data);
        $this->eventForm['name'] = '';
        $this->eventForm['question'] = '';
        $this->eventForm['event_type_id'] = null;
        $this->eventForm['save_as_template'] = false;
        $this->customOptionsText = '';
        $this->showEventModal = false;
        $this->refreshEventTypes();
        $this->refreshRounds();
        $this->dispatch('event-created');
    }

    public function moveEvent(int $eventId, string $direction, ReorderRoundEvents $reorderRoundEvents): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $event = $this->managedEvent($eventId);
        $round = $event->round()->firstOrFail();
        $eventIds = $round->events()
            ->orderBy('position')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $currentIndex = array_search($event->id, $eventIds, true);
        abort_if($currentIndex === false, 404);

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if (! array_key_exists($targetIndex, $eventIds)) {
            return;
        }

        [$eventIds[$currentIndex], $eventIds[$targetIndex]] = [$eventIds[$targetIndex], $eventIds[$currentIndex]];
        $reorderRoundEvents->handle($round, auth()->user(), $eventIds);

        $this->refreshRounds();
        $this->dispatch('events-reordered');
    }

    public function openEvent(int $eventId, SynchronizeEventLifecycle $lifecycle): void
    {
        $event = $this->managedEvent($eventId);
        $lifecycle->transition($event, EventStatus::Open, auth()->user());
        $this->refreshRounds();
    }

    public function lockEvent(int $eventId, SynchronizeEventLifecycle $lifecycle): void
    {
        $event = $this->managedEvent($eventId);
        $lifecycle->transition($event, EventStatus::Locked, auth()->user());
        $this->refreshRounds();
    }

    public function startCancellation(int $eventId): void
    {
        $event = $this->managedEvent($eventId);
        abort_unless(in_array($event->effectiveStatus(), [EventStatus::Draft, EventStatus::Open, EventStatus::Locked], true), 422);

        $this->cancellingEventId = $event->id;
        $this->cancellationReason = '';
        $this->showCancellationModal = true;
    }

    public function cancelEvent(SynchronizeEventLifecycle $lifecycle): void
    {
        $validated = $this->validate([
            'cancellationReason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $event = $this->managedEvent($this->cancellingEventId);
        $lifecycle->transition($event, EventStatus::Cancelled, auth()->user(), $validated['cancellationReason']);

        $this->showCancellationModal = false;
        $this->cancellingEventId = null;
        $this->refreshRounds();
        $this->dispatch('event-cancelled');
    }

    public function submitPrediction(int $eventId, bool $submit, SubmitEventPrediction $submitEventPrediction): void
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('predict', $event);
        $member = $this->pool->memberFor(auth()->user());
        abort_if($member === null, 403);

        $submitEventPrediction->handle($event, $member, $this->predictionSelections[$eventId] ?? [], $submit);
        $this->refreshRounds();
        $this->dispatch('prediction-saved');
    }

    public function publishResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('recordResult', $event);
        $selectionIds = $this->normalizedResultSelections($eventId);
        if (($this->resultPreviewFingerprints[$eventId] ?? null) !== $this->resultSelectionFingerprint($selectionIds)) {
            throw ValidationException::withMessages([
                "resultSelections.{$eventId}" => __('Generate a fresh score preview before publishing this result.'),
            ]);
        }

        $result = $publishEventResult->handle(
            $event,
            auth()->user(),
            $selectionIds,
            $this->correctionReasons[$eventId] ?? null,
        );
        unset($this->resultPreviewFingerprints[$eventId], $this->scorePreviews[$eventId]);
        $this->refreshRounds();
        $this->dispatch($result->status === 'published' ? 'result-published' : 'result-recorded');
    }

    public function publishRecordedResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->pool->events()->with('draftResult')->findOrFail($eventId);
        Gate::authorize('publishResult', $event);
        abort_if($event->draftResult === null, 404);

        $publishEventResult->publishDraft($event->draftResult, auth()->user());
        $this->refreshRounds();
        $this->dispatch('result-published');
    }

    public function startAmendingRecordedResult(int $eventId): void
    {
        $event = $this->pool->events()->with('draftResult')->findOrFail($eventId);
        Gate::authorize('recordResult', $event);
        abort_if($event->draftResult === null, 404);
        Gate::authorize('amend', $event->draftResult);

        if (! in_array($eventId, $this->editingDraftResultIds, true)) {
            $this->editingDraftResultIds[] = $eventId;
        }

        unset($this->resultPreviewFingerprints[$eventId], $this->scorePreviews[$eventId]);
        $this->resetErrorBag("resultSelections.{$eventId}");
    }

    public function amendRecordedResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->pool->events()->with('draftResult')->findOrFail($eventId);
        Gate::authorize('recordResult', $event);
        abort_if($event->draftResult === null, 404);
        $selectionIds = $this->normalizedResultSelections($eventId);

        if (($this->resultPreviewFingerprints[$eventId] ?? null) !== $this->resultSelectionFingerprint($selectionIds)) {
            throw ValidationException::withMessages([
                "resultSelections.{$eventId}" => __('Generate a fresh score preview before updating this result.'),
            ]);
        }

        $publishEventResult->amendDraft(
            $event->draftResult,
            auth()->user(),
            $selectionIds,
            $this->correctionReasons[$eventId] ?? null,
        );

        $this->editingDraftResultIds = array_values(array_diff($this->editingDraftResultIds, [$eventId]));
        unset($this->resultPreviewFingerprints[$eventId], $this->scorePreviews[$eventId]);
        $this->refreshRounds();
        $this->dispatch('result-amended');
    }

    public function previewResult(int $eventId, PreviewEventScore $previewEventScore): void
    {
        $this->resetErrorBag("resultSelections.{$eventId}");
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('recordResult', $event);
        $selectionIds = $this->normalizedResultSelections($eventId);
        $this->scorePreviews[$eventId] = $previewEventScore
            ->handle($event, $selectionIds)
            ->all();
        $this->resultPreviewFingerprints[$eventId] = $this->resultSelectionFingerprint($selectionIds);
    }

    public function updatedResultSelections(mixed $value, string $eventId): void
    {
        if (ctype_digit($eventId)) {
            unset($this->resultPreviewFingerprints[(int) $eventId], $this->scorePreviews[(int) $eventId]);
        }
    }

    public function saveOfficialRules(int $poolEventId, UpdatePoolEventRules $updatePoolEventRules): void
    {
        Gate::authorize('update', $this->pool);
        $poolEvent = $this->pool->poolEvents()->whereNotNull('season_event_id')->findOrFail($poolEventId);
        $formKey = "officialRuleForms.{$poolEventId}";
        $validated = $this->validate([
            "{$formKey}.mode" => ['required', 'in:roster,prediction,hybrid'],
            "{$formKey}.visibility" => ['required', 'in:after_lock,after_publish'],
            "{$formKey}.prediction_min_selections" => ['required', 'integer', 'min:0', 'max:50'],
            "{$formKey}.prediction_max_selections" => ['required', 'integer', 'min:1', 'max:50'],
            "{$formKey}.owner_points" => ['required', 'integer', 'between:-100,100'],
            "{$formKey}.prediction_points" => ['required', 'integer', 'between:-100,100'],
            "{$formKey}.exact_bonus" => ['required', 'integer', 'between:-100,100'],
            "{$formKey}.wrong_penalty" => ['required', 'integer', 'between:-100,0'],
            "{$formKey}.allow_negative" => ['required', 'boolean'],
        ]);

        $updatePoolEventRules->handle(
            $poolEvent,
            auth()->user(),
            data_get($validated, $formKey),
        );

        $this->refreshRounds();
        $this->dispatch('pool-event-rules-updated');
    }

    private function managedEvent(int $eventId): Event
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('update', $event);

        return $event;
    }

    private function refreshEventTypes(): void
    {
        $this->eventTypes = EventType::query()
            ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $this->pool->id))
            ->orderByDesc('is_standard')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('update', $this->pool);
    }

    /** @return Collection<int, SeasonRound> */
    #[Computed]
    public function officialRounds(): Collection
    {
        $poolId = $this->pool->id;
        $officialRounds = SeasonRound::query()
            ->where('season_id', $this->pool->season_id)
            ->whereHas('events.poolEvents', fn ($query) => $query->where('pool_id', $poolId)->where('is_active', true))
            ->with([
                'events' => fn ($query) => $query
                    ->whereHas('poolEvents', fn ($poolEventQuery) => $poolEventQuery->where('pool_id', $poolId)->where('is_active', true))
                    ->with('options'),
                'events.poolEvents' => fn ($query) => $query
                    ->where('pool_id', $poolId)
                    ->where('is_active', true)
                    ->withCount([
                        'predictions',
                        'pointEntries' => fn ($pointQuery) => $pointQuery->published(),
                    ]),
            ])
            ->orderBy('position')
            ->get();

        return $officialRounds;
    }

    /** @return Collection<int, Round> */
    #[Computed]
    public function rounds(): Collection
    {
        $member = $this->pool->memberFor(auth()->user());
        $canManage = $this->canManage;

        return $this->pool->rounds()
            ->with(['events' => function ($query) use ($canManage, $member): void {
                $query->withCount('predictions')->with([
                    'eventType', 'options', 'latestResult.options',
                    'results' => fn ($resultQuery) => $resultQuery
                        ->when(! $canManage, fn ($publishedQuery) => $publishedQuery->where('status', 'published'))
                        ->with(['options', 'createdBy']),
                    'predictions' => fn ($predictionQuery) => $predictionQuery
                        ->where('pool_member_id', $member?->id)
                        ->with(['options', 'poolMember.user']),
                ])->when($canManage, fn ($eventQuery) => $eventQuery->with('draftResult.options'))
                    ->orderBy('position');
            }])
            ->orderBy('position')
            ->get();
    }

    private function refreshRounds(): void
    {
        unset($this->rounds);

        $rounds = $this->rounds;
        $this->activeMembers = $this->pool->activeMembers()->with('user')->get()->sortBy('user.name')->values();

        $this->reorderableRoundIds = $this->canManage
            ? $rounds
                ->filter(fn (Round $round): bool => $round->events->count() > 1
                    && $round->events->every(fn (Event $event): bool => $event->effectiveStatus() === EventStatus::Draft
                        && $event->predictions_count === 0
                        && $event->results->isEmpty()))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        foreach ($rounds->flatMap->events as $event) {
            $prediction = $event->predictions->first();
            $this->predictionSelections[$event->id] = $prediction?->options->pluck('id')->all() ?? [];
            $recordedResult = $this->canManage ? $event->draftResult : null;
            $this->resultSelections[$event->id] = ($recordedResult ?? $event->latestResult)?->options->pluck('id')->all() ?? [];
            $this->correctionReasons[$event->id] = $recordedResult?->correction_reason ?? ($this->correctionReasons[$event->id] ?? '');

            if (in_array($event->effectiveStatus(), [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published], true)) {
                $event->load(['predictions' => fn ($query) => $query
                    ->whereIn('status', ['submitted', 'locked'])
                    ->with(['options', 'poolMember.user'])]);
            }
        }

        $this->localRespondedMemberIds = [];
        if ($this->canManage) {
            $localEventIds = $rounds->flatMap->events->pluck('id');
            $this->localRespondedMemberIds = EventPrediction::query()
                ->whereIn('event_id', $localEventIds)
                ->whereIn('status', ['submitted', 'locked'])
                ->get(['event_id', 'pool_member_id'])
                ->groupBy('event_id')
                ->map(fn ($predictions) => $predictions->pluck('pool_member_id')->unique()->values()->all())
                ->all();
        }

        $officialRounds = $this->officialRounds;

        foreach ($officialRounds->flatMap->events as $event) {
            $event->poolEvents->each(function ($poolEvent): void {
                $this->officialRuleForms[$poolEvent->id] = [
                    'mode' => $poolEvent->mode->value,
                    'visibility' => $poolEvent->visibility,
                    'prediction_min_selections' => $poolEvent->prediction_min_selections,
                    'prediction_max_selections' => $poolEvent->prediction_max_selections,
                    'owner_points' => (int) data_get($poolEvent->scoring_config, 'owner.points_per_match', 0),
                    'prediction_points' => (int) data_get($poolEvent->scoring_config, 'prediction.points_per_correct', 0),
                    'exact_bonus' => (int) data_get($poolEvent->scoring_config, 'prediction.exact_match_bonus', 0),
                    'wrong_penalty' => (int) data_get($poolEvent->scoring_config, 'prediction.wrong_answer_penalty', 0),
                    'allow_negative' => (bool) data_get($poolEvent->scoring_config, 'allow_negative', false),
                ];
            });

        }

        $this->officialRespondedMemberIds = [];
        if ($this->canManage) {
            $officialPoolEventIds = $officialRounds->flatMap->events->flatMap->poolEvents->pluck('id');
            $this->officialRespondedMemberIds = PoolEventPrediction::query()
                ->whereIn('pool_event_id', $officialPoolEventIds)
                ->whereIn('status', ['submitted', 'locked'])
                ->get(['pool_event_id', 'pool_member_id'])
                ->groupBy('pool_event_id')
                ->map(fn ($predictions) => $predictions->pluck('pool_member_id')->unique()->values()->all())
                ->all();
        }
    }

    /** @return list<int> */
    private function normalizedResultSelections(int $eventId): array
    {
        $selectionIds = array_values(array_unique(array_map('intval', $this->resultSelections[$eventId] ?? [])));
        sort($selectionIds);

        return $selectionIds;
    }

    /** @param list<int> $selectionIds */
    private function resultSelectionFingerprint(array $selectionIds): string
    {
        return hash('sha256', implode(':', $selectionIds));
    }
};
