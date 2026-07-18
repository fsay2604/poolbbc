<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\ResultPublicationMode;
use App\Jobs\FinalizeSeasonEventResultJob;
use App\Jobs\ScoreSeasonEventResultJob;
use App\Models\SeasonEvent;
use App\Models\SeasonEventResult;
use App\Models\SeasonEventResultScoringReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishSeasonEventResult
{
    public function __construct(
        private TransitionSeasonEvent $transitionSeasonEvent,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param list<int> $optionIds */
    public function handle(SeasonEvent $event, User $administrator, array $optionIds, ?string $correctionReason = null): SeasonEventResult
    {
        return DB::transaction(function () use ($event, $administrator, $optionIds, $correctionReason): SeasonEventResult {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($administrator)->authorize('recordResult', $event);
            $event = $this->transitionSeasonEvent->synchronize($event);
            $optionIds = $this->validateOptions($event, $optionIds);

            if (! in_array($event->status, [EventStatus::Locked, EventStatus::Published], true)) {
                throw ValidationException::withMessages(['result' => __('This official event is not ready for a result.')]);
            }

            if ($event->results()->whereIn('status', ['draft', 'pending', 'failed'])->exists()) {
                throw ValidationException::withMessages([
                    'result' => __('An official result is already awaiting publication for this event.'),
                ]);
            }

            $previous = $event->results()->where('status', 'published')->first();
            if ($previous !== null && blank($correctionReason)) {
                throw ValidationException::withMessages([
                    'correction_reason' => __('A correction reason is required.'),
                ]);
            }

            if ($previous === null) {
                $event = $this->transitionSeasonEvent->transition($event, EventStatus::ResultEntered, $administrator);
            }

            $result = $event->results()->create([
                'created_by' => $administrator->id,
                'supersedes_id' => $previous?->id,
                'version' => ($previous?->version ?? 0) + 1,
                'status' => 'draft',
                'correction_reason' => $correctionReason,
                'published_at' => null,
            ]);
            $result->options()->sync($optionIds);

            if (ResultPublicationMode::from((string) $event->getRawOriginal('result_publication_mode')) === ResultPublicationMode::Immediate) {
                return $this->queueLocked($event, $result, $administrator);
            }

            $this->recordAuditLog->handle(null, $administrator, $previous ? 'season_event.correction_recorded' : 'season_event.result_recorded', $result, [
                'version' => $result->version,
                'correction_reason' => $correctionReason,
                'option_ids' => $optionIds,
            ]);

            return $result->fresh(['options']);
        }, attempts: 3);
    }

    /** @param list<int> $optionIds */
    public function amendDraft(SeasonEventResult $result, User $administrator, array $optionIds, ?string $correctionReason = null): SeasonEventResult
    {
        return DB::transaction(function () use ($result, $administrator, $optionIds, $correctionReason): SeasonEventResult {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($result->season_event_id);
            Gate::forUser($administrator)->authorize('recordResult', $event);
            $result = SeasonEventResult::query()
                ->where('season_event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($result->id);

            if ($result->status !== 'draft') {
                throw ValidationException::withMessages(['result' => __('This official result is no longer awaiting publication.')]);
            }

            Gate::forUser($administrator)->authorize('amend', $result);
            $optionIds = $this->validateOptions($event, $optionIds);
            $correctionReason = filled($correctionReason) ? trim($correctionReason) : null;

            if ($correctionReason !== null && mb_strlen($correctionReason) > 1000) {
                throw ValidationException::withMessages(['correction_reason' => __('The correction reason may not be greater than 1000 characters.')]);
            }

            if ($result->supersedes_id !== null && $correctionReason === null) {
                throw ValidationException::withMessages(['correction_reason' => __('A correction reason is required.')]);
            }

            $before = [
                'option_ids' => $result->options()->pluck('season_event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
                'correction_reason' => $result->correction_reason,
            ];
            $result->update(['correction_reason' => $correctionReason]);
            $result->options()->sync($optionIds);
            $after = [
                'option_ids' => $optionIds,
                'correction_reason' => $correctionReason,
            ];

            $this->recordAuditLog->handle(null, $administrator, 'season_event.result_draft_amended', $result, [
                'version' => $result->version,
                'option_ids' => $optionIds,
                'correction_reason' => $correctionReason,
                'before' => $before,
                'after' => $after,
            ]);

            return $result->fresh(['options']);
        }, attempts: 3);
    }

    public function publishDraft(SeasonEventResult $result, User $administrator): SeasonEventResult
    {
        return DB::transaction(function () use ($result, $administrator): SeasonEventResult {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($result->season_event_id);
            Gate::forUser($administrator)->authorize('publishResult', $event);
            $result = SeasonEventResult::query()
                ->where('season_event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($result->id);
            Gate::forUser($administrator)->authorize('publish', $result);

            $expectedEventStatus = $result->supersedes_id === null
                ? EventStatus::ResultEntered
                : EventStatus::Published;
            if ($event->status !== $expectedEventStatus) {
                throw ValidationException::withMessages(['result' => __('This official event is not ready to publish the recorded result.')]);
            }

            return $this->queueLocked($event, $result, $administrator);
        }, attempts: 3);
    }

    public function retry(SeasonEventResult $result, User $administrator): SeasonEventResult
    {
        return DB::transaction(function () use ($result, $administrator): SeasonEventResult {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($result->season_event_id);
            Gate::forUser($administrator)->authorize('publishResult', $event);
            $result = SeasonEventResult::query()
                ->where('season_event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($result->id);
            Gate::forUser($administrator)->authorize('retry', $result);

            $expectedEventStatus = $result->supersedes_id === null
                ? EventStatus::ResultEntered
                : EventStatus::Published;
            if ($event->status !== $expectedEventStatus) {
                throw ValidationException::withMessages([
                    'result' => __('This official event is not ready to retry result publication.'),
                ]);
            }

            if ($result->status === 'failed') {
                $result->update([
                    'status' => 'pending',
                    'published_at' => null,
                ]);
            }

            $this->syncScoringReceipts($event, $result);
            $incompleteReceiptCount = $this->dispatchIncompleteScoring($event, $result);

            $this->recordAuditLog->handle(
                null,
                $administrator,
                'season_event.result_scoring_retried',
                $result,
                [
                    'version' => $result->version,
                    'correction_reason' => $result->correction_reason,
                    'option_ids' => $result->options()->pluck('season_event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
                    'incomplete_receipt_count' => $incompleteReceiptCount,
                ],
            );

            return $result->fresh(['options', 'pointEntries', 'scoringReceipts']);
        }, attempts: 3);
    }

    /**
     * @param  list<int>  $optionIds
     * @return list<int>
     */
    private function validateOptions(SeasonEvent $event, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        $validOptions = $event->options()->whereKey($optionIds)->get(['id', 'is_none']);

        if ($validOptions->count() !== count($optionIds)
            || count($optionIds) < $event->result_min_selections
            || count($optionIds) > $event->result_max_selections) {
            throw ValidationException::withMessages(['result' => __('The official result selection is invalid.')]);
        }

        if (count($optionIds) > 1 && $validOptions->contains('is_none', true)) {
            throw ValidationException::withMessages([
                'result' => __('The explicit none option cannot be combined with another answer.'),
            ]);
        }

        sort($optionIds);

        return $optionIds;
    }

    private function queueLocked(SeasonEvent $event, SeasonEventResult $result, User $administrator): SeasonEventResult
    {
        $result->update(['status' => 'pending']);
        $this->syncScoringReceipts($event, $result);
        $this->dispatchIncompleteScoring($event, $result);

        $this->recordAuditLog->handle(null, $administrator, $result->supersedes_id ? 'season_event.correction_scoring_started' : 'season_event.result_scoring_started', $result, [
            'version' => $result->version,
            'correction_reason' => $result->correction_reason,
            'option_ids' => $result->options()->pluck('season_event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
        ]);

        return $result->fresh(['options', 'pointEntries', 'scoringReceipts']);
    }

    private function syncScoringReceipts(SeasonEvent $event, SeasonEventResult $result): void
    {
        $event->poolEvents()
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $poolEventId) => $result->scoringReceipts()->firstOrCreate([
                'pool_event_id' => $poolEventId,
            ]));
    }

    private function dispatchIncompleteScoring(SeasonEvent $event, SeasonEventResult $result): int
    {
        $activePoolEventIds = $event->poolEvents()
            ->where('is_active', true)
            ->pluck('id');
        $incompletePoolEventIds = SeasonEventResultScoringReceipt::query()
            ->where('season_event_result_id', $result->id)
            ->whereIn('pool_event_id', $activePoolEventIds)
            ->whereNull('completed_at')
            ->orderBy('pool_event_id')
            ->pluck('pool_event_id');
        $jobs = $incompletePoolEventIds
            ->map(fn (int $poolEventId): ScoreSeasonEventResultJob => new ScoreSeasonEventResultJob($result->id, $poolEventId))
            ->push(new FinalizeSeasonEventResultJob($result->id))
            ->all();

        DB::afterCommit(fn () => Bus::chain($jobs)->dispatch());

        return $incompletePoolEventIds->count();
    }
}
