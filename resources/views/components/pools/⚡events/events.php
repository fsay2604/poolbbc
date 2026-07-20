<?php

use App\Actions\Events\CreateEvent;
use App\Actions\Events\CreateRound;
use App\Actions\Events\DeleteEvent;
use App\Actions\Events\DeleteRound;
use App\Actions\Events\ReorderRoundEvents;
use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Events\UpdateEvent;
use App\Actions\Events\UpdatePoolEventRules;
use App\Actions\Events\UpdateRound;
use App\Actions\Pools\ListUserPools;
use App\Enums\AnswerSource;
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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
    public array $eventForm = [];

    public string $customOptionsText = '';

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

    public bool $showRoundDeletionModal = false;

    public bool $showEventDeletionModal = false;

    #[Locked]
    public ?int $editingRoundId = null;

    #[Locked]
    public ?int $deletingRoundId = null;

    #[Locked]
    public ?int $editingEventId = null;

    #[Locked]
    public ?int $deletingEventId = null;

    #[Locked]
    public ?int $cancellingEventId = null;

    public string $deletingRoundName = '';

    public string $deletingEventName = '';

    public string $cancellationReason = '';

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);

        $this->pool = $pool->load('season');
        abort_unless($this->pool->isManagedBy(auth()->user()) || Gate::allows('admin'), 403);

        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->resetEventForm();
        $this->refreshEventTypes();
        $this->refreshStructure();
    }

    public function startRoundCreation(): void
    {
        Gate::authorize('update', $this->pool);
        $this->editingRoundId = null;
        $this->roundForm = ['name' => '', 'starts_at' => null, 'ends_at' => null];
        $this->resetErrorBag();
        $this->showRoundModal = true;
    }

    public function createRound(CreateRound $createRound): void
    {
        Gate::authorize('update', $this->pool);
        $request = new CreateRoundRequest;
        $validated = $this->validate($request->rules());
        $createRound->handle($this->pool, auth()->user(), $validated['roundForm']);

        $this->closeRoundModal();
        $this->refreshStructure();
        $this->dispatch('round-created');
    }

    public function startRoundEdit(int $roundId): void
    {
        $round = $this->managedRound($roundId);
        $this->editingRoundId = $round->id;
        $this->roundForm = [
            'name' => $round->name,
            'starts_at' => $round->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $round->ends_at?->format('Y-m-d\TH:i'),
        ];
        $this->resetErrorBag();
        $this->showRoundModal = true;
    }

    public function updateRound(UpdateRound $updateRound): void
    {
        abort_if($this->editingRoundId === null, 422);
        $request = new CreateRoundRequest;
        $validated = $this->validate($request->rules());
        $round = $this->managedRound($this->editingRoundId);

        $updateRound->handle($this->pool, $round, auth()->user(), $validated['roundForm']);
        $this->closeRoundModal();
        $this->refreshStructure();
        $this->dispatch('round-updated');
    }

    public function startRoundDeletion(int $roundId): void
    {
        $round = $this->managedRound($roundId);
        $this->deletingRoundId = $round->id;
        $this->deletingRoundName = $round->name;
        $this->resetValidation('roundDeletion');
        $this->showRoundDeletionModal = true;
    }

    public function deleteRound(DeleteRound $deleteRound): void
    {
        abort_if($this->deletingRoundId === null, 422);
        $round = $this->managedRound($this->deletingRoundId);
        $deleteRound->handle($this->pool, $round, auth()->user());

        $this->showRoundDeletionModal = false;
        $this->deletingRoundId = null;
        $this->refreshStructure();
        $this->dispatch('round-deleted');
    }

    public function startEventCreation(): void
    {
        Gate::authorize('update', $this->pool);
        abort_if($this->rounds->isEmpty(), 422);

        $this->editingEventId = null;
        $this->resetEventForm((int) $this->rounds->first()->id);
        $this->resetErrorBag();
        $this->showEventModal = true;
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
        $createEvent->handle($this->pool, auth()->user(), $this->validatedEventData());

        $this->closeEventModal();
        $this->refreshEventTypes();
        $this->refreshStructure();
        $this->dispatch('event-created');
    }

    public function startEventEdit(int $eventId): void
    {
        $event = $this->managedEvent($eventId)->load('options.houseguest');
        $this->editingEventId = $event->id;
        $this->eventForm = [
            'round_id' => $event->round_id,
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'question' => $event->question ?? '',
            'mode' => $event->mode->value,
            'answer_source' => $event->answer_source->value,
            'opens_at' => $event->opens_at?->format('Y-m-d\TH:i'),
            'locks_at' => $event->locks_at?->format('Y-m-d\TH:i'),
            'prediction_min_selections' => $event->prediction_min_selections,
            'prediction_max_selections' => $event->prediction_max_selections,
            'result_min_selections' => $event->result_min_selections,
            'result_max_selections' => $event->result_max_selections,
            'result_publication_mode' => $event->result_publication_mode->value,
            'owner_points' => (int) data_get($event->scoring_config, 'owner.points_per_match', 0),
            'prediction_points' => (int) data_get($event->scoring_config, 'prediction.points_per_correct', 0),
            'exact_bonus' => (int) data_get($event->scoring_config, 'prediction.exact_match_bonus', 0),
            'wrong_penalty' => (int) data_get($event->scoring_config, 'prediction.wrong_answer_penalty', 0),
            'allow_negative' => (bool) data_get($event->scoring_config, 'allow_negative', false),
            'allow_none' => $event->options->contains('is_none', true),
            'include_inactive_houseguests' => $event->options->contains(
                fn ($option): bool => $option->houseguest_id !== null && $option->houseguest?->is_active === false,
            ),
            'save_as_template' => false,
        ];
        $this->customOptionsText = $event->answer_source === AnswerSource::Custom
            ? $event->options->pluck('label')->implode("\n")
            : '';
        $this->resetErrorBag();
        $this->showEventModal = true;
    }

    public function updateEvent(UpdateEvent $updateEvent): void
    {
        abort_if($this->editingEventId === null, 422);
        $event = $this->managedEvent($this->editingEventId);
        $updateEvent->handle($this->pool, $event, auth()->user(), $this->validatedEventData());

        $this->closeEventModal();
        $this->refreshStructure();
        $this->dispatch('event-updated');
    }

    public function startEventDeletion(int $eventId): void
    {
        $event = $this->managedEvent($eventId);
        Gate::authorize('delete', $event);
        $this->deletingEventId = $event->id;
        $this->deletingEventName = $event->name;
        $this->resetValidation('eventDeletion');
        $this->showEventDeletionModal = true;
    }

    public function deleteEvent(DeleteEvent $deleteEvent): void
    {
        abort_if($this->deletingEventId === null, 422);
        $event = $this->managedEvent($this->deletingEventId);
        $deleteEvent->handle($this->pool, $event, auth()->user());

        $this->showEventDeletionModal = false;
        $this->deletingEventId = null;
        $this->refreshStructure();
        $this->dispatch('event-deleted');
    }

    public function moveEvent(int $eventId, string $direction, ReorderRoundEvents $reorderRoundEvents): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $event = $this->managedEvent($eventId);
        $round = $event->round()->firstOrFail();
        $eventIds = $round->events()->orderBy('position')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $currentIndex = array_search($event->id, $eventIds, true);
        abort_if($currentIndex === false, 404);

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if (! array_key_exists($targetIndex, $eventIds)) {
            return;
        }

        [$eventIds[$currentIndex], $eventIds[$targetIndex]] = [$eventIds[$targetIndex], $eventIds[$currentIndex]];
        $reorderRoundEvents->handle($round, auth()->user(), $eventIds);

        $this->refreshStructure();
        $this->dispatch('events-reordered');
    }

    public function openEvent(int $eventId, SynchronizeEventLifecycle $lifecycle): void
    {
        $lifecycle->transition($this->managedEvent($eventId), EventStatus::Open, auth()->user());
        $this->refreshStructure();
    }

    public function lockEvent(int $eventId, SynchronizeEventLifecycle $lifecycle): void
    {
        $lifecycle->transition($this->managedEvent($eventId), EventStatus::Locked, auth()->user());
        $this->refreshStructure();
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
        $validated = $this->validate(['cancellationReason' => ['required', 'string', 'min:3', 'max:1000']]);
        $event = $this->managedEvent($this->cancellingEventId);
        $lifecycle->transition($event, EventStatus::Cancelled, auth()->user(), $validated['cancellationReason']);

        $this->showCancellationModal = false;
        $this->cancellingEventId = null;
        $this->refreshStructure();
        $this->dispatch('event-cancelled');
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

        $updatePoolEventRules->handle($poolEvent, auth()->user(), data_get($validated, $formKey));
        $this->refreshStructure();
        $this->dispatch('pool-event-rules-updated');
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

        return SeasonRound::query()
            ->where('season_id', $this->pool->season_id)
            ->whereHas('events.poolEvents', fn ($query) => $query->where('pool_id', $poolId)->where('is_active', true))
            ->with([
                'events' => fn ($query) => $query
                    ->whereHas('poolEvents', fn ($poolEventQuery) => $poolEventQuery->where('pool_id', $poolId)->where('is_active', true))
                    ->with('options'),
                'events.poolEvents' => fn ($query) => $query
                    ->where('pool_id', $poolId)
                    ->where('is_active', true)
                    ->withCount(['predictions', 'pointEntries' => fn ($pointQuery) => $pointQuery->published()]),
            ])
            ->orderBy('position')
            ->get();
    }

    /** @return Collection<int, Round> */
    #[Computed]
    public function rounds(): Collection
    {
        return $this->pool->rounds()
            ->with(['events' => fn ($query) => $query
                ->with(['eventType', 'options'])
                ->withCount(['predictions', 'results'])
                ->orderBy('position')])
            ->orderBy('position')
            ->get();
    }

    /** @return array<string, mixed> */
    private function validatedEventData(): array
    {
        $request = new CreateEventRequest;
        $validated = $this->validate($request->rules());
        $form = $validated['eventForm'];

        if ($form['event_type_id'] !== null) {
            EventType::query()
                ->whereKey($form['event_type_id'])
                ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $this->pool->id))
                ->firstOrFail();
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

        return $data;
    }

    private function managedRound(int $roundId): Round
    {
        Gate::authorize('update', $this->pool);

        return $this->pool->rounds()->findOrFail($roundId);
    }

    private function managedEvent(int $eventId): Event
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('update', $event);

        return $event;
    }

    private function resetEventForm(?int $roundId = null): void
    {
        $this->eventForm = [
            'round_id' => $roundId ?? '',
            'event_type_id' => null,
            'name' => '',
            'question' => '',
            'mode' => 'prediction',
            'answer_source' => 'houseguests',
            'opens_at' => null,
            'locks_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
            'result_publication_mode' => 'immediate',
            'owner_points' => 5,
            'prediction_points' => 2,
            'exact_bonus' => 0,
            'wrong_penalty' => 0,
            'allow_negative' => false,
            'allow_none' => false,
            'include_inactive_houseguests' => false,
            'save_as_template' => false,
        ];
        $this->customOptionsText = '';
    }

    private function closeRoundModal(): void
    {
        $this->showRoundModal = false;
        $this->editingRoundId = null;
        $this->roundForm = ['name' => '', 'starts_at' => null, 'ends_at' => null];
    }

    private function closeEventModal(): void
    {
        $this->showEventModal = false;
        $this->editingEventId = null;
        $this->resetEventForm();
    }

    private function refreshEventTypes(): void
    {
        $this->eventTypes = EventType::query()
            ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $this->pool->id))
            ->orderByDesc('is_standard')
            ->orderBy('name')
            ->get();
    }

    private function refreshStructure(): void
    {
        unset($this->rounds, $this->officialRounds);

        $rounds = $this->rounds;
        $this->activeMembers = $this->pool->activeMembers()->with('user')->get()->sortBy('user.name')->values();

        $this->reorderableRoundIds = $this->canManage
            ? $rounds
                ->filter(fn (Round $round): bool => $round->events->count() > 1
                    && $round->events->every(fn (Event $event): bool => $event->effectiveStatus() === EventStatus::Draft
                        && $event->predictions_count === 0
                        && $event->results_count === 0))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $this->localRespondedMemberIds = [];
        if ($this->canManage) {
            $this->localRespondedMemberIds = EventPrediction::query()
                ->whereIn('event_id', $rounds->flatMap->events->pluck('id'))
                ->whereIn('status', ['submitted', 'locked'])
                ->get(['event_id', 'pool_member_id'])
                ->groupBy('event_id')
                ->map(fn ($predictions) => $predictions->pluck('pool_member_id')->unique()->values()->all())
                ->all();
        }

        $officialRounds = $this->officialRounds;
        foreach ($officialRounds->flatMap->events as $event) {
            foreach ($event->poolEvents as $poolEvent) {
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
            }
        }

        $this->officialRespondedMemberIds = [];
        if ($this->canManage) {
            $this->officialRespondedMemberIds = PoolEventPrediction::query()
                ->whereIn('pool_event_id', $officialRounds->flatMap->events->flatMap->poolEvents->pluck('id'))
                ->whereIn('status', ['submitted', 'locked'])
                ->get(['pool_event_id', 'pool_member_id'])
                ->groupBy('pool_event_id')
                ->map(fn ($predictions) => $predictions->pluck('pool_member_id')->unique()->values()->all())
                ->all();
        }
    }
};
