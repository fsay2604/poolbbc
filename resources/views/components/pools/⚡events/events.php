<?php

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\CreateEvent;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Actions\Scoring\PreviewEventScore;
use App\Actions\Scoring\PublishEventResult;
use App\Enums\EventStatus;
use App\Http\Requests\Events\CreateEventRequest;
use App\Http\Requests\Pools\CreateRoundRequest;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public $rounds;

    public $eventTypes;

    public bool $canManage = false;

    /** @var array<string, mixed> */
    public array $roundForm = ['name' => '', 'starts_at' => null, 'ends_at' => null];

    /** @var array<string, mixed> */
    public array $eventForm = [
        'round_id' => '', 'event_type_id' => null, 'name' => '', 'question' => '',
        'mode' => 'prediction', 'answer_source' => 'houseguests', 'opens_at' => null, 'locks_at' => null,
        'prediction_min_selections' => 1, 'prediction_max_selections' => 1,
        'result_min_selections' => 1, 'result_max_selections' => 1,
        'owner_points' => 5, 'prediction_points' => 2, 'exact_bonus' => 0,
        'wrong_penalty' => 0, 'allow_negative' => false, 'allow_none' => false,
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

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool->load('season');
        $this->canManage = Gate::allows('update', $pool);
        $this->eventTypes = EventType::query()
            ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $pool->id))
            ->orderByDesc('is_standard')
            ->orderBy('name')
            ->get();
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
        $this->customOptionsText = '';
        $this->refreshRounds();
        $this->dispatch('event-created');
    }

    public function openEvent(int $eventId, RecordAuditLog $recordAuditLog): void
    {
        $event = $this->managedEvent($eventId);
        abort_unless($event->status === EventStatus::Draft, 422);
        $event->update(['status' => EventStatus::Open, 'opens_at' => $event->opens_at ?? now()]);
        $recordAuditLog->handle($this->pool, auth()->user(), 'event.opened', $event);
        $this->refreshRounds();
    }

    public function lockEvent(int $eventId, RecordAuditLog $recordAuditLog): void
    {
        $event = $this->managedEvent($eventId);
        abort_unless($event->status === EventStatus::Open, 422);
        $event->update(['status' => EventStatus::Locked]);
        $recordAuditLog->handle($this->pool, auth()->user(), 'event.locked', $event);
        $this->refreshRounds();
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
        Gate::authorize('publishResult', $event);
        $publishEventResult->handle(
            $event,
            auth()->user(),
            $this->resultSelections[$eventId] ?? [],
            $this->correctionReasons[$eventId] ?? null,
        );
        $this->refreshRounds();
        $this->dispatch('result-published');
    }

    public function previewResult(int $eventId, PreviewEventScore $previewEventScore): void
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('publishResult', $event);
        $this->scorePreviews[$eventId] = $previewEventScore
            ->handle($event, $this->resultSelections[$eventId] ?? [])
            ->all();
    }

    private function managedEvent(int $eventId): Event
    {
        $event = $this->pool->events()->findOrFail($eventId);
        Gate::authorize('update', $event);

        return $event;
    }

    private function refreshRounds(): void
    {
        $member = $this->pool->memberFor(auth()->user());
        $this->rounds = $this->pool->rounds()
            ->with(['events' => function ($query) use ($member): void {
                $query->withCount('predictions')->with([
                    'eventType', 'options', 'latestResult.options',
                    'predictions' => fn ($predictionQuery) => $predictionQuery
                        ->where('pool_member_id', $member?->id)
                        ->with(['options', 'poolMember.user']),
                ])->orderBy('position');
            }])
            ->orderBy('position')
            ->get();

        foreach ($this->rounds->flatMap->events as $event) {
            $prediction = $event->predictions->first();
            $this->predictionSelections[$event->id] = $prediction?->options->pluck('id')->all() ?? [];
            $this->resultSelections[$event->id] = $event->latestResult?->options->pluck('id')->all() ?? [];

            if (in_array($event->status, [EventStatus::Locked, EventStatus::Published], true)) {
                $event->load(['predictions' => fn ($query) => $query->with(['options', 'poolMember.user'])]);
            }
        }
    }
};
