<?php

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\SynchronizeOfficialPoolEvents;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Scoring\PreviewSeasonEventScore;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Enums\ResultPublicationMode;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public $seasons;

    /** @var Collection<int, SeasonRound> */
    public Collection $rounds;

    public int $wizardStep = 1;

    public ?int $seasonId = null;

    public string $template = 'standard';

    public string $roundName = '';

    public string $opensAt = '';

    public string $locksAt = '';

    public bool $showEditModal = false;

    public ?int $editingEventId = null;

    /** @var array{name:string,question:string,default_mode:string,opens_at:string,locks_at:string,prediction_min_selections:int,prediction_max_selections:int,result_min_selections:int,result_max_selections:int,result_publication_mode:string,include_inactive_houseguests:bool,allow_none:bool} */
    public array $eventForm = [
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

    public ?int $cancellingEventId = null;

    public string $cancellationReason = '';

    public bool $showResultModal = false;

    public ?int $resultEventId = null;

    /** @var list<int> */
    public array $resultOptionIds = [];

    public string $correctionReason = '';

    /** @var list<array{pool:string,member:string,roster_points:int,prediction_points:int,total_points:int}> */
    public array $previewRows = [];

    public ?string $resultPreviewFingerprint = null;

    public function mount(): void
    {
        Gate::authorize('admin');
        $this->seasons = Season::query()->orderByDesc('is_active')->orderByDesc('id')->get();
        $this->seasonId = $this->seasons->first()?->id;
        $this->refreshRounds();
    }

    public function updatedSeasonId(): void
    {
        $this->refreshRounds();
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
        $opensAt = Carbon::parse($validated['opensAt'], config('app.timezone'));
        $locksAt = Carbon::parse($validated['locksAt'], config('app.timezone'));
        $createRound->handle(
            $season,
            auth()->user(),
            OfficialRoundTemplate::from($validated['template']),
            $validated['roundName'],
            $opensAt,
            $locksAt,
        );

        $this->wizardStep = 1;
        $this->roundName = '';
        $this->opensAt = '';
        $this->locksAt = '';
        $this->refreshRounds();
        $this->dispatch('official-round-created');
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

    public function startEdit(int $eventId): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
        abort_unless($event->effectiveStatus() === EventStatus::Draft && $event->options_locked_at === null, 422);

        $this->editingEventId = $event->id;
        $this->eventForm = [
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
        $this->showEditModal = true;
    }

    public function saveEvent(
        RecordAuditLog $recordAuditLog,
        SynchronizeOfficialPoolEvents $synchronizeOfficialPoolEvents,
    ): void {
        Gate::authorize('admin');

        $validated = $this->validate([
            'eventForm.name' => ['required', 'string', 'max:255'],
            'eventForm.question' => ['nullable', 'string', 'max:1000'],
            'eventForm.default_mode' => ['required', Rule::enum(EventMode::class)],
            'eventForm.opens_at' => ['required', 'date', 'after:now'],
            'eventForm.locks_at' => ['required', 'date', 'after:eventForm.opens_at'],
            'eventForm.prediction_min_selections' => ['required', 'integer', 'min:0'],
            'eventForm.prediction_max_selections' => ['required', 'integer', 'gte:eventForm.prediction_min_selections'],
            'eventForm.result_min_selections' => ['required', 'integer', 'min:0'],
            'eventForm.result_max_selections' => ['required', 'integer', 'gte:eventForm.result_min_selections'],
            'eventForm.result_publication_mode' => ['required', Rule::enum(ResultPublicationMode::class)],
            'eventForm.include_inactive_houseguests' => ['required', 'boolean'],
            'eventForm.allow_none' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($validated, $recordAuditLog, $synchronizeOfficialPoolEvents): void {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($this->editingEventId);
            abort_unless($event->effectiveStatus() === EventStatus::Draft && $event->options_locked_at === null, 422);
            if (Carbon::parse($validated['eventForm']['opens_at'], config('app.timezone'))->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'eventForm.opens_at' => __('The opening date must be in the future.'),
                ]);
            }

            $before = $event->only(array_keys($validated['eventForm']));
            $event->update($validated['eventForm']);
            $synchronizeOfficialPoolEvents->handleEvent($event);
            $recordAuditLog->handle(null, auth()->user(), 'season_event.updated', $event, [
                'before' => $before,
                'after' => $event->only(array_keys($validated['eventForm'])),
            ]);
        });

        $this->showEditModal = false;
        $this->refreshRounds();
        $this->dispatch('official-event-updated');
    }

    public function startCancellation(int $eventId): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
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

    public function startResult(int $eventId): void
    {
        $event = SeasonEvent::query()->with(['options', 'latestResult', 'draftResult'])->findOrFail($eventId);
        Gate::authorize('recordResult', $event);
        abort_unless(in_array($event->effectiveStatus(), [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published], true), 422);

        $this->resultEventId = $event->id;
        $this->resultOptionIds = ($event->draftResult ?? $event->latestResult)?->options()->pluck('season_event_options.id')->all() ?? [];
        sort($this->resultOptionIds);
        $this->correctionReason = $event->draftResult?->correction_reason ?? '';
        $this->previewRows = [];
        $this->resultPreviewFingerprint = null;
        $this->showResultModal = true;
    }

    public function updatedResultOptionIds(): void
    {
        $this->previewRows = [];
        $this->resultPreviewFingerprint = null;
        $this->resetValidation('resultOptionIds');
    }

    public function previewResult(PreviewSeasonEventScore $preview): void
    {
        $this->resetValidation('resultOptionIds');
        $event = SeasonEvent::query()->findOrFail($this->resultEventId);
        $optionIds = $this->normalizedResultOptionIds();
        $this->resultOptionIds = $optionIds;
        $this->previewRows = $preview->handle($event, auth()->user(), $optionIds)->all();
        $this->resultPreviewFingerprint = $this->resultFingerprint($optionIds);
    }

    public function publishResult(PublishSeasonEventResult $publish): void
    {
        $event = SeasonEvent::query()->with('latestResult')->findOrFail($this->resultEventId);
        $optionIds = $this->requireFreshResultPreview();
        $result = $publish->handle(
            $event,
            auth()->user(),
            $optionIds,
            $event->latestResult === null ? null : $this->correctionReason,
        );

        $this->showResultModal = false;
        $this->resultPreviewFingerprint = null;
        $this->refreshRounds();
        $this->dispatch($result->status === 'draft' ? 'official-result-recorded' : 'official-result-queued');
    }

    public function amendResult(PublishSeasonEventResult $publish): void
    {
        $event = SeasonEvent::query()->with('draftResult')->findOrFail($this->resultEventId);
        abort_if($event->draftResult === null, 404);

        $publish->amendDraft(
            $event->draftResult,
            auth()->user(),
            $this->requireFreshResultPreview(),
            $this->correctionReason,
        );

        $this->showResultModal = false;
        $this->resultPreviewFingerprint = null;
        $this->refreshRounds();
        $this->dispatch('official-result-amended');
    }

    public function publishRecordedResult(int $eventId, PublishSeasonEventResult $publish): void
    {
        $event = SeasonEvent::query()->with('draftResult')->findOrFail($eventId);
        Gate::authorize('publishResult', $event);
        abort_if($event->draftResult === null, 404);

        $publish->publishDraft($event->draftResult, auth()->user());
        $this->refreshRounds();
        $this->dispatch('official-result-queued');
    }

    public function retryPublication(int $eventId, PublishSeasonEventResult $publish): void
    {
        $event = SeasonEvent::query()->findOrFail($eventId);
        Gate::authorize('publishResult', $event);
        $result = $event->results()
            ->whereIn('status', ['pending', 'failed'])
            ->orderByDesc('version')
            ->firstOrFail();

        $publish->retry($result, auth()->user());
        $this->refreshRounds();
        $this->dispatch('official-result-retried');
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

    /** @return list<int> */
    private function normalizedResultOptionIds(): array
    {
        $optionIds = array_values(array_unique(array_map('intval', $this->resultOptionIds)));
        sort($optionIds);

        return $optionIds;
    }

    /** @param list<int> $optionIds */
    private function resultFingerprint(array $optionIds): string
    {
        return hash('sha256', json_encode([
            'event_id' => $this->resultEventId,
            'option_ids' => $optionIds,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<int> */
    private function requireFreshResultPreview(): array
    {
        $optionIds = $this->normalizedResultOptionIds();
        $expectedFingerprint = $this->resultFingerprint($optionIds);

        if ($this->resultPreviewFingerprint === null || ! hash_equals($expectedFingerprint, $this->resultPreviewFingerprint)) {
            throw ValidationException::withMessages([
                'resultOptionIds' => __('Generate a new preview before confirming this official result.'),
            ]);
        }

        return $optionIds;
    }

    private function refreshRounds(): void
    {
        $this->rounds = $this->seasonId === null
            ? collect()
            : SeasonRound::query()
                ->where('season_id', $this->seasonId)
                ->with([
                    'events.options',
                    'events.latestResult.options',
                    'events.draftResult.options',
                    'events.results.options',
                    'events.results.creator',
                    'events.poolEvents.pool',
                ])
                ->orderBy('position')
                ->get();
    }
};
