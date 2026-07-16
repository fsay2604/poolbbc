<?php

namespace App\Actions\Scoring;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishEventResult
{
    public function __construct(
        private ScoringEngine $scoringEngine,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param list<int> $optionIds */
    public function handle(Event $event, User $administrator, array $optionIds, ?string $correctionReason = null): EventResult
    {
        return DB::transaction(function () use ($event, $administrator, $optionIds, $correctionReason): EventResult {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
            $validOptionIds = $event->options()->whereKey($optionIds)->pluck('id')->all();

            if (count($validOptionIds) !== count($optionIds)
                || count($optionIds) < $event->result_min_selections
                || count($optionIds) > $event->result_max_selections) {
                throw ValidationException::withMessages(['result' => __('The official result selection is invalid.')]);
            }

            if ($event->status === EventStatus::Open && $event->locks_at?->isPast()) {
                $event->update(['status' => EventStatus::Locked]);
            }

            if (! in_array($event->status, [EventStatus::Locked, EventStatus::Published], true)) {
                throw ValidationException::withMessages(['result' => __('This event is not ready for a result.')]);
            }

            $previousResult = $event->results()->first();
            if ($previousResult !== null && blank($correctionReason)) {
                throw ValidationException::withMessages(['correction_reason' => __('A correction reason is required.')]);
            }

            $result = $event->results()->create([
                'created_by' => $administrator->id,
                'supersedes_id' => $previousResult?->id,
                'version' => ($previousResult?->version ?? 0) + 1,
                'status' => 'published',
                'correction_reason' => $correctionReason,
                'published_at' => now(),
            ]);
            $result->options()->sync($optionIds);

            if ($previousResult !== null) {
                $this->reversePreviousScore($previousResult, $result);
            }

            $this->scoringEngine->score($result->fresh(['event', 'options.houseguest']));
            $event->update(['status' => EventStatus::Published, 'published_at' => now()]);

            $this->recordAuditLog->handle($event->pool, $administrator, $previousResult ? 'event.result_corrected' : 'event.result_published', $result, [
                'version' => $result->version,
                'correction_reason' => $correctionReason,
            ]);

            return $result->fresh(['options', 'pointEntries']);
        }, attempts: 3);
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
