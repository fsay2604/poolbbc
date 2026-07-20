<?php

use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Scoring\PreviewSeasonEventScore;
use App\Enums\EventStatus;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public $seasons;

    /** @var Collection<int, SeasonRound> */
    public Collection $rounds;

    public ?int $seasonId = null;

    public bool $showResultModal = false;

    #[Locked]
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
        $this->resetErrorBag();
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
        $result = $publish->handle(
            $event,
            auth()->user(),
            $this->requireFreshResultPreview(),
            $event->latestResult === null ? null : $this->correctionReason,
        );

        $this->closeResultModal();
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

        $this->closeResultModal();
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

    private function closeResultModal(): void
    {
        $this->showResultModal = false;
        $this->resultEventId = null;
        $this->resultPreviewFingerprint = null;
    }

    private function refreshRounds(): void
    {
        if ($this->seasonId === null) {
            $this->rounds = collect();

            return;
        }

        $this->rounds = SeasonRound::query()
            ->where('season_id', $this->seasonId)
            ->with([
                'events.options',
                'events.latestResult.options',
                'events.draftResult.options',
                'events.results.options',
                'events.results.creator',
            ])
            ->orderBy('position')
            ->get()
            ->map(function (SeasonRound $round): SeasonRound {
                $round->setRelation('events', $round->events
                    ->filter(fn (SeasonEvent $event): bool => in_array(
                        $event->effectiveStatus(),
                        [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published],
                        true,
                    ))
                    ->values());

                return $round;
            })
            ->filter(fn (SeasonRound $round): bool => $round->events->isNotEmpty())
            ->values();
    }
};
