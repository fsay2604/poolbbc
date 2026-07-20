<?php

use App\Actions\Events\CreateSeasonEvent;
use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\DeleteSeasonEvent;
use App\Actions\Events\DeleteSeasonRound;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Events\UpdateSeasonEvent;
use App\Actions\Events\UpdateSeasonRound;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Http\Requests\Events\CreateSeasonEventRequest;
use App\Http\Requests\Events\UpdateSeasonEventRequest;
use App\Http\Requests\Events\UpdateSeasonRoundRequest;
use App\Models\EventType;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public $seasons;

    public $eventTypes;

    /** @var Collection<int, SeasonRound> */
    public Collection $rounds;

    public int $wizardStep = 1;

    public ?int $seasonId = null;

    public string $template = 'standard';

    public string $roundName = '';

    public string $opensAt = '';

    public string $locksAt = '';

    public bool $showRoundModal = false;

    #[Locked]
    public ?int $editingRoundId = null;

    /** @var array{name:string,starts_at:string,ends_at:string} */
    public array $roundForm = [
        'name' => '',
        'starts_at' => '',
        'ends_at' => '',
    ];

    public bool $showEventModal = false;

    #[Locked]
    public ?int $creatingRoundId = null;

    #[Locked]
    public ?int $editingEventId = null;

    /** @var array{event_type_id:int|null,name:string,question:string,default_mode:string,opens_at:string,locks_at:string,prediction_min_selections:int,prediction_max_selections:int,result_min_selections:int,result_max_selections:int,result_publication_mode:string,include_inactive_houseguests:bool,allow_none:bool} */
    public array $eventForm = [
        'event_type_id' => null,
        'name' => '',
        'question' => '',
        'default_mode' => 'hybrid',
        'opens_at' => '',
        'locks_at' => '',
        'prediction_min_selections' => 1,
        'prediction_max_selections' => 1,
        'result_min_selections' => 1,
        'result_max_selections' => 1,
        'result_publication_mode' => 'immediate',
        'include_inactive_houseguests' => false,
        'allow_none' => false,
    ];

    public bool $showCancellationModal = false;

    #[Locked]
    public ?int $cancellingEventId = null;

    public string $cancellationReason = '';

    public bool $showEventDeletionModal = false;

    #[Locked]
    public ?int $deletingEventId = null;

    public string $deletingEventName = '';

    public bool $showRoundDeletionModal = false;

    #[Locked]
    public ?int $deletingRoundId = null;

    public string $deletingRoundName = '';

    public function mount(StandardEventTypeCatalog $catalog): void
    {
        Gate::authorize('admin');
        $this->seasons = Season::query()->orderByDesc('is_active')->orderByDesc('id')->get();
        $this->seasonId = $this->seasons->first()?->id;
        $this->refreshEventTypes($catalog);
        $this->refreshRounds();
    }

    public function updatedSeasonId(): void
    {
        $this->refreshRounds();
    }

    public function updatedEventFormEventTypeId(mixed $value, StandardEventTypeCatalog $catalog): void
    {
        if ($this->creatingRoundId === null || $this->eventForm['event_type_id'] === null) {
            return;
        }

        $round = SeasonRound::query()->findOrFail($this->creatingRoundId);
        $this->initializeEventForm(
            $this->managedEventType((int) $value, $catalog),
            $catalog,
            $round,
        );
    }

    public function reviewWizard(): void
    {
        $this->validateWizard();
        $this->wizardStep = 2;
    }

    public function backToWizard(): void
    {
        $this->wizardStep = 1;
    }

    public function createRound(CreateSeasonRoundFromTemplate $createRound): void
    {
        $validated = $this->validateWizard();
        $season = Season::query()->findOrFail($validated['seasonId']);
        $createRound->handle(
            $season,
            auth()->user(),
            OfficialRoundTemplate::from($validated['template']),
            $validated['roundName'],
            Carbon::parse($validated['opensAt'], config('app.timezone')),
            Carbon::parse($validated['locksAt'], config('app.timezone')),
        );

        $this->wizardStep = 1;
        $this->roundName = '';
        $this->opensAt = '';
        $this->locksAt = '';
        $this->refreshRounds();
        $this->dispatch('official-round-created');
    }

    public function startRoundEdit(int $roundId): void
    {
        $round = SeasonRound::query()->findOrFail($roundId);
        Gate::authorize('update', $round);
        $this->editingRoundId = $round->id;
        $this->roundForm = [
            'name' => $round->name,
            'starts_at' => $round->starts_at?->format('Y-m-d\TH:i') ?? '',
            'ends_at' => $round->ends_at?->format('Y-m-d\TH:i') ?? '',
        ];
        $this->resetErrorBag();
        $this->showRoundModal = true;
    }

    public function saveRound(UpdateSeasonRound $updateSeasonRound): void
    {
        abort_if($this->editingRoundId === null, 422);
        $request = new UpdateSeasonRoundRequest;
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
        $round = SeasonRound::query()->findOrFail($this->editingRoundId);

        $updateSeasonRound->handle($round, auth()->user(), $validated['roundForm']);

        $this->showRoundModal = false;
        $this->editingRoundId = null;
        $this->refreshRounds();
        $this->dispatch('official-round-updated');
    }

    public function startCreateEvent(int $roundId, StandardEventTypeCatalog $catalog): void
    {
        $round = SeasonRound::query()->findOrFail($roundId);
        Gate::authorize('create', SeasonEvent::class);
        $eventType = $this->eventTypes->first();

        if (! $eventType instanceof EventType) {
            $this->addError('eventForm.event_type_id', __('Create a managed standard event type before adding an official event.'));

            return;
        }

        $this->creatingRoundId = $round->id;
        $this->editingEventId = null;
        $this->initializeEventForm($eventType, $catalog, $round);
        $this->resetErrorBag();
        $this->showEventModal = true;
    }

    public function startEdit(int $eventId): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
        Gate::authorize('update', $event);

        if ($event->effectiveStatus() !== EventStatus::Draft || $event->options_locked_at !== null) {
            $this->addError('eventForm', __('Official event rules cannot change after opening or the first response.'));

            return;
        }

        $this->creatingRoundId = null;
        $this->editingEventId = $event->id;
        $this->eventForm = [
            'event_type_id' => $event->event_type_id,
            'name' => $event->name,
            'question' => $event->question ?? '',
            'default_mode' => $event->default_mode->value,
            'opens_at' => $event->opens_at?->format('Y-m-d\TH:i') ?? '',
            'locks_at' => $event->locks_at?->format('Y-m-d\TH:i') ?? '',
            'prediction_min_selections' => $event->prediction_min_selections,
            'prediction_max_selections' => $event->prediction_max_selections,
            'result_min_selections' => $event->result_min_selections,
            'result_max_selections' => $event->result_max_selections,
            'result_publication_mode' => $event->result_publication_mode->value,
            'include_inactive_houseguests' => $event->include_inactive_houseguests,
            'allow_none' => $event->allow_none,
        ];
        $this->resetErrorBag();
        $this->showEventModal = true;
    }

    public function saveEvent(
        CreateSeasonEvent $createSeasonEvent,
        UpdateSeasonEvent $updateSeasonEvent,
    ): void {
        if ($this->creatingRoundId !== null) {
            $request = new CreateSeasonEventRequest;
            $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
            $round = SeasonRound::query()->findOrFail($this->creatingRoundId);
            $createSeasonEvent->handle($round, auth()->user(), $validated['eventForm']);
            $event = 'official-event-created';
        } else {
            abort_if($this->editingEventId === null, 422);
            $request = new UpdateSeasonEventRequest;
            $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());
            $officialEvent = SeasonEvent::query()->findOrFail($this->editingEventId);
            $updateSeasonEvent->handle($officialEvent, auth()->user(), $validated['eventForm']);
            $event = 'official-event-updated';
        }

        $this->showEventModal = false;
        $this->creatingRoundId = null;
        $this->editingEventId = null;
        $this->refreshRounds();
        $this->dispatch($event);
    }

    public function openEvent(int $eventId, TransitionSeasonEvent $transition): void
    {
        $transition->transition(SeasonEvent::query()->findOrFail($eventId), EventStatus::Open, auth()->user());
        $this->refreshRounds();
        $this->dispatch('official-event-updated');
    }

    public function lockEvent(int $eventId, TransitionSeasonEvent $transition): void
    {
        $transition->transition(SeasonEvent::query()->findOrFail($eventId), EventStatus::Locked, auth()->user());
        $this->refreshRounds();
        $this->dispatch('official-event-updated');
    }

    public function startCancellation(int $eventId): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
        Gate::authorize('transition', $event);
        abort_unless(in_array($event->effectiveStatus(), [EventStatus::Draft, EventStatus::Open, EventStatus::Locked], true), 422);

        $this->cancellingEventId = $event->id;
        $this->cancellationReason = '';
        $this->showCancellationModal = true;
    }

    public function cancelEvent(TransitionSeasonEvent $transition): void
    {
        $validated = $this->validate([
            'cancellationReason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $event = SeasonEvent::query()->findOrFail($this->cancellingEventId);
        $transition->transition($event, EventStatus::Cancelled, auth()->user(), $validated['cancellationReason']);

        $this->showCancellationModal = false;
        $this->cancellingEventId = null;
        $this->refreshRounds();
        $this->dispatch('official-event-cancelled');
    }

    public function startEventDeletion(int $eventId): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
        Gate::authorize('delete', $event);
        $this->resetValidation('eventDeletion');
        $this->deletingEventId = $event->id;
        $this->deletingEventName = $event->name;
        $this->showEventDeletionModal = true;
    }

    public function deleteEvent(DeleteSeasonEvent $deleteSeasonEvent): void
    {
        abort_if($this->deletingEventId === null, 422);
        $deleteSeasonEvent->handle(
            SeasonEvent::query()->findOrFail($this->deletingEventId),
            auth()->user(),
        );

        $this->showEventDeletionModal = false;
        $this->deletingEventId = null;
        $this->refreshRounds();
        $this->dispatch('official-event-deleted');
    }

    public function startRoundDeletion(int $roundId): void
    {
        $round = SeasonRound::query()->findOrFail($roundId);
        Gate::authorize('delete', $round);
        $this->resetValidation('roundDeletion');
        $this->deletingRoundId = $round->id;
        $this->deletingRoundName = $round->name;
        $this->showRoundDeletionModal = true;
    }

    public function deleteRound(DeleteSeasonRound $deleteSeasonRound): void
    {
        abort_if($this->deletingRoundId === null, 422);
        $deleteSeasonRound->handle(
            SeasonRound::query()->findOrFail($this->deletingRoundId),
            auth()->user(),
        );

        $this->showRoundDeletionModal = false;
        $this->deletingRoundId = null;
        $this->refreshRounds();
        $this->dispatch('official-round-deleted');
    }

    /** @return array{seasonId:int,template:string,roundName:string,opensAt:string,locksAt:string} */
    private function validateWizard(): array
    {
        return $this->validate([
            'seasonId' => ['required', 'integer', 'exists:seasons,id'],
            'template' => ['required', 'in:'.collect(OfficialRoundTemplate::cases())->pluck('value')->implode(',')],
            'roundName' => ['required', 'string', 'max:255'],
            'opensAt' => ['required', 'date', 'after:now'],
            'locksAt' => ['required', 'date', 'after:opensAt'],
        ], attributes: [
            'seasonId' => __('season'),
            'template' => __('round template'),
            'roundName' => __('round name'),
            'opensAt' => __('opening date'),
            'locksAt' => __('locking date'),
        ]);
    }

    private function managedEventType(int $eventTypeId, StandardEventTypeCatalog $catalog): EventType
    {
        return EventType::query()
            ->whereKey($eventTypeId)
            ->whereNull('pool_id')
            ->where('is_standard', true)
            ->whereIn('slug', $catalog->slugs())
            ->firstOrFail();
    }

    private function initializeEventForm(
        EventType $eventType,
        StandardEventTypeCatalog $catalog,
        SeasonRound $round,
    ): void {
        $definition = $catalog->seasonEventAttributes($eventType);
        $this->eventForm = [
            'event_type_id' => $eventType->id,
            'name' => $definition['name'],
            'question' => $definition['question'] ?? '',
            'default_mode' => $eventType->default_mode->value,
            'opens_at' => $round->starts_at?->format('Y-m-d\TH:i') ?? now()->addHour()->format('Y-m-d\TH:i'),
            'locks_at' => $round->ends_at?->format('Y-m-d\TH:i') ?? now()->addDay()->format('Y-m-d\TH:i'),
            'prediction_min_selections' => $definition['prediction_min_selections'],
            'prediction_max_selections' => $definition['prediction_max_selections'],
            'result_min_selections' => $definition['result_min_selections'],
            'result_max_selections' => $definition['result_max_selections'],
            'result_publication_mode' => $definition['result_publication_mode'],
            'include_inactive_houseguests' => $definition['include_inactive_houseguests'],
            'allow_none' => $definition['allow_none'],
        ];
    }

    private function refreshEventTypes(StandardEventTypeCatalog $catalog): void
    {
        $this->eventTypes = EventType::query()
            ->whereNull('pool_id')
            ->where('is_standard', true)
            ->whereIn('slug', $catalog->slugs())
            ->orderBy('name')
            ->get();
    }

    private function refreshRounds(): void
    {
        $this->rounds = $this->seasonId === null
            ? collect()
            : SeasonRound::query()
                ->where('season_id', $this->seasonId)
                ->with(['events' => fn ($query) => $query
                    ->with('eventType')
                    ->withCount('poolEvents')])
                ->orderBy('position')
                ->get();
    }
};
