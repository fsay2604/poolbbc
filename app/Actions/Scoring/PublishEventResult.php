<?php

namespace App\Actions\Scoring;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Enums\EventStatus;
use App\Enums\ResultPublicationMode;
use App\Models\Event;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishEventResult
{
    public function __construct(
        private ScoringEngine $scoringEngine,
        private RecordAuditLog $recordAuditLog,
        private SynchronizeEventLifecycle $eventLifecycle,
    ) {}

    /** @param list<int> $optionIds */
    public function handle(Event $event, User $administrator, array $optionIds, ?string $correctionReason = null): EventResult
    {
        return DB::transaction(function () use ($event, $administrator, $optionIds, $correctionReason): EventResult {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($administrator)->authorize('recordResult', $event);
            $event = $this->eventLifecycle->synchronize($event, $administrator);
            $optionIds = $this->validateOptions($event, $optionIds);

            if (! in_array($event->status, [EventStatus::Locked, EventStatus::Published], true)) {
                throw ValidationException::withMessages(['result' => __('This event is not ready for a result.')]);
            }

            if ($event->results()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages([
                    'result' => __('A result is already awaiting publication for this event.'),
                ]);
            }

            $previousResult = $event->results()->where('status', 'published')->first();
            if ($previousResult !== null && blank($correctionReason)) {
                throw ValidationException::withMessages(['correction_reason' => __('A correction reason is required.')]);
            }

            if ($previousResult === null) {
                $event = $this->eventLifecycle->transition($event, EventStatus::ResultEntered, $administrator);
            }

            $result = $event->results()->create([
                'created_by' => $administrator->id,
                'supersedes_id' => $previousResult?->id,
                'version' => ($previousResult?->version ?? 0) + 1,
                'status' => 'draft',
                'correction_reason' => $correctionReason,
                'published_at' => null,
            ]);
            $result->options()->sync($optionIds);

            if ($event->result_publication_mode === ResultPublicationMode::Immediate) {
                return $this->publishLocked($event, $result, $administrator);
            }

            $this->recordAuditLog->handle($event->pool, $administrator, $previousResult ? 'event.correction_recorded' : 'event.result_recorded', $result, [
                'version' => $result->version,
                'correction_reason' => $correctionReason,
                'option_ids' => $optionIds,
            ]);

            return $result->fresh(['options']);
        }, attempts: 3);
    }

    public function publishDraft(EventResult $result, User $administrator): EventResult
    {
        return DB::transaction(function () use ($result, $administrator): EventResult {
            $event = Event::query()->lockForUpdate()->findOrFail($result->event_id);
            Gate::forUser($administrator)->authorize('publishResult', $event);
            $result = EventResult::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($result->id);

            if ($result->status !== 'draft') {
                throw ValidationException::withMessages(['result' => __('This result is no longer awaiting publication.')]);
            }

            $expectedEventStatus = $result->supersedes_id === null
                ? EventStatus::ResultEntered
                : EventStatus::Published;
            if ($event->status !== $expectedEventStatus) {
                throw ValidationException::withMessages(['result' => __('This event is not ready to publish the recorded result.')]);
            }

            return $this->publishLocked($event, $result, $administrator);
        }, attempts: 3);
    }

    /** @param list<int> $optionIds */
    public function amendDraft(EventResult $result, User $administrator, array $optionIds, ?string $correctionReason = null): EventResult
    {
        return DB::transaction(function () use ($result, $administrator, $optionIds, $correctionReason): EventResult {
            $event = Event::query()->lockForUpdate()->findOrFail($result->event_id);
            Gate::forUser($administrator)->authorize('recordResult', $event);
            $result = EventResult::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($result->id);

            if ($result->status !== 'draft') {
                throw ValidationException::withMessages(['result' => __('This result is no longer awaiting publication.')]);
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
                'option_ids' => $result->options()->pluck('event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
                'correction_reason' => $result->correction_reason,
            ];
            $result->update(['correction_reason' => $correctionReason]);
            $result->options()->sync($optionIds);
            $after = [
                'option_ids' => $optionIds,
                'correction_reason' => $correctionReason,
            ];

            $this->recordAuditLog->handle($event->pool, $administrator, 'event.result_draft_amended', $result, [
                'version' => $result->version,
                'option_ids' => $optionIds,
                'correction_reason' => $correctionReason,
                'before' => $before,
                'after' => $after,
            ]);

            return $result->fresh(['options']);
        }, attempts: 3);
    }

    /**
     * @param  list<int>  $optionIds
     * @return list<int>
     */
    private function validateOptions(Event $event, array $optionIds): array
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

    private function publishLocked(Event $event, EventResult $result, User $administrator): EventResult
    {
        $previousResult = $result->supersedes_id === null
            ? null
            : EventResult::query()->where('status', 'published')->findOrFail($result->supersedes_id);

        if ($previousResult !== null) {
            $this->reversePreviousScore($previousResult, $result);
        }

        $this->scoringEngine->score($result->fresh(['event', 'options.houseguest']));
        $result->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        if ($event->status === EventStatus::ResultEntered) {
            $this->eventLifecycle->transition($event, EventStatus::Published, $administrator);
        }

        DB::afterCommit(fn () => Cache::forget(BuildPoolLeaderboard::cacheKey($event->pool_id)));
        $this->recordAuditLog->handle($event->pool, $administrator, $previousResult ? 'event.result_corrected' : 'event.result_published', $result, [
            'version' => $result->version,
            'correction_reason' => $result->correction_reason,
            'option_ids' => $result->options()->pluck('event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
        ]);

        return $result->fresh(['options', 'pointEntries']);
    }

    private function reversePreviousScore(EventResult $previousResult, EventResult $newResult): void
    {
        $previousResult->pointEntries()
            ->whereNull('reverses_point_entry_id')
            ->get()
            ->each(function (PointEntry $entry) use ($newResult, $previousResult): void {
                PointEntry::query()->firstOrCreate(
                    ['idempotency_key' => "result:{$newResult->id}:reversal:{$entry->id}"],
                    [
                        'pool_member_id' => $entry->pool_member_id,
                        'event_id' => $entry->event_id,
                        'event_result_id' => $newResult->id,
                        'event_prediction_id' => $entry->event_prediction_id,
                        'reverses_point_entry_id' => $entry->id,
                        'type' => 'reversal',
                        'points' => -$entry->points,
                        'reason' => __('Correction of result version :version', ['version' => $previousResult->version]),
                    ],
                );
            });
    }
}
