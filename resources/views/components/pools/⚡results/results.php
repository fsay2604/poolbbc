<?php

use App\Actions\Pools\ListUserPools;
use App\Actions\Scoring\PreviewEventScore;
use App\Actions\Scoring\PublishEventResult;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Pool;
use App\Models\Round;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public $availablePools;

    /** @var Collection<int, Round> */
    public Collection $rounds;

    public bool $canManageLocalResults = false;

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

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);

        $this->pool = $pool->load('season');
        $this->canManageLocalResults = Gate::allows('update', $this->pool);
        abort_unless($this->pool->isManagedBy(auth()->user()) || Gate::allows('admin'), 403);

        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->refreshResults();
    }

    public function publishResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->managedResultEvent($eventId);
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
        $this->refreshResults();
        $this->dispatch($result->status === 'published' ? 'result-published' : 'result-recorded');
    }

    public function publishRecordedResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->managedResultEvent($eventId, ['draftResult']);
        Gate::authorize('publishResult', $event);
        abort_if($event->draftResult === null, 404);

        $publishEventResult->publishDraft($event->draftResult, auth()->user());
        $this->refreshResults();
        $this->dispatch('result-published');
    }

    public function startAmendingRecordedResult(int $eventId): void
    {
        $event = $this->managedResultEvent($eventId, ['draftResult.options']);
        abort_if($event->draftResult === null, 404);
        Gate::authorize('amend', $event->draftResult);

        if (! in_array($eventId, $this->editingDraftResultIds, true)) {
            $this->editingDraftResultIds[] = $eventId;
        }

        $this->resultSelections[$eventId] = $event->draftResult->options->pluck('id')->all();
        $this->correctionReasons[$eventId] = $event->draftResult->correction_reason ?? '';
        unset($this->resultPreviewFingerprints[$eventId], $this->scorePreviews[$eventId]);
        $this->resetErrorBag("resultSelections.{$eventId}");
    }

    public function amendRecordedResult(int $eventId, PublishEventResult $publishEventResult): void
    {
        $event = $this->managedResultEvent($eventId, ['draftResult']);
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
        $this->refreshResults();
        $this->dispatch('result-amended');
    }

    public function previewResult(int $eventId, PreviewEventScore $previewEventScore): void
    {
        $this->resetErrorBag("resultSelections.{$eventId}");
        $event = $this->managedResultEvent($eventId);
        $selectionIds = $this->normalizedResultSelections($eventId);
        $this->scorePreviews[$eventId] = $previewEventScore->handle($event, $selectionIds)->all();
        $this->resultPreviewFingerprints[$eventId] = $this->resultSelectionFingerprint($selectionIds);
    }

    public function updatedResultSelections(mixed $value, string $eventId): void
    {
        if (ctype_digit($eventId)) {
            unset($this->resultPreviewFingerprints[(int) $eventId], $this->scorePreviews[(int) $eventId]);
        }
    }

    /** @param list<string> $relations */
    private function managedResultEvent(int $eventId, array $relations = []): Event
    {
        abort_unless($this->canManageLocalResults, 403);

        $event = $this->pool->events()->with($relations)->findOrFail($eventId);
        Gate::authorize('recordResult', $event);

        return $event;
    }

    private function refreshResults(): void
    {
        $canManageLocalResults = $this->canManageLocalResults;

        $this->rounds = $this->pool->rounds()
            ->with(['events' => function ($query) use ($canManageLocalResults): void {
                $query->with([
                    'options',
                    'latestResult.options',
                    'results' => fn ($resultQuery) => $resultQuery
                        ->when(! $canManageLocalResults, fn ($publishedQuery) => $publishedQuery->where('status', 'published'))
                        ->with(['options', 'createdBy']),
                ])
                    ->when($canManageLocalResults, fn ($eventQuery) => $eventQuery->with('draftResult.options'))
                    ->orderBy('position');
            }])
            ->orderBy('position')
            ->get()
            ->map(function (Round $round): Round {
                $round->setRelation('events', $round->events
                    ->filter(fn (Event $event): bool => in_array(
                        $event->effectiveStatus(),
                        [EventStatus::Locked, EventStatus::ResultEntered, EventStatus::Published],
                        true,
                    ))
                    ->values());

                return $round;
            })
            ->filter(fn (Round $round): bool => $round->events->isNotEmpty())
            ->values();

        if (! $canManageLocalResults) {
            return;
        }

        foreach ($this->rounds->flatMap->events as $event) {
            $recordedResult = $event->draftResult;
            $this->resultSelections[$event->id] = ($recordedResult ?? $event->latestResult)?->options->pluck('id')->all() ?? [];
            $this->correctionReasons[$event->id] = $recordedResult?->correction_reason ?? ($this->correctionReasons[$event->id] ?? '');
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
