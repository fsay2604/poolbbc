<?php

namespace App\Jobs;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Enums\EventStatus;
use App\Models\Pool;
use App\Models\SeasonEvent;
use App\Models\SeasonEventResult;
use App\Models\SeasonEventResultScoringReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeSeasonEventResultJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $seasonEventResultId) {}

    public function uniqueId(): string
    {
        return (string) $this->seasonEventResultId;
    }

    public function handle(
        TransitionSeasonEvent $transition,
        RecordAuditLog $recordAuditLog,
        RebuildSeasonHouseguestActivity $rebuildHouseguestActivity,
        RebuildPoolScoreProjection $rebuildPoolScoreProjection,
    ): void {
        DB::transaction(function () use ($transition, $recordAuditLog, $rebuildHouseguestActivity, $rebuildPoolScoreProjection): void {
            $result = SeasonEventResult::query()
                ->with('creator')
                ->lockForUpdate()
                ->findOrFail($this->seasonEventResultId);

            if (! in_array($result->status, ['pending', 'failed', 'published'], true)) {
                return;
            }

            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($result->season_event_id);
            $activePoolEventIds = $event->poolEvents()
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');
            if (in_array($result->status, ['pending', 'failed'], true)) {
                $expectedEventStatus = $result->supersedes_id === null
                    ? EventStatus::ResultEntered
                    : EventStatus::Published;
                if ($event->status !== $expectedEventStatus) {
                    return;
                }

                $completedPoolEventIds = SeasonEventResultScoringReceipt::query()
                    ->where('season_event_result_id', $result->id)
                    ->whereIn('pool_event_id', $activePoolEventIds)
                    ->whereNotNull('completed_at')
                    ->pluck('pool_event_id');
                if ($activePoolEventIds->diff($completedPoolEventIds)->isNotEmpty()) {
                    return;
                }
            }

            if ($result->status === 'failed') {
                $result->update([
                    'status' => 'pending',
                    'published_at' => null,
                ]);
            }

            $wasPending = $result->status === 'pending';
            if ($wasPending) {
                $result->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
                if ($result->supersedes_id === null) {
                    $transition->transitionForSystem($event, EventStatus::Published);
                }
                $recordAuditLog->handle(
                    null,
                    $result->creator,
                    $result->supersedes_id === null ? 'season_event.result_published' : 'season_event.result_corrected',
                    $result,
                    [
                        'version' => $result->version,
                        'correction_reason' => $result->correction_reason,
                        'option_ids' => $result->options()->pluck('season_event_options.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
                    ],
                );
            }

            $poolIds = $event->poolEvents()
                ->where('is_active', true)
                ->pluck('pool_id')
                ->unique()
                ->sort()
                ->values();

            $poolIds->each(function (int $poolId) use ($rebuildPoolScoreProjection): void {
                $rebuildPoolScoreProjection->handle(Pool::query()->findOrFail($poolId));
            });

            if ($wasPending) {
                $event->load('round.season');
                $rebuildHouseguestActivity->handle($event->round->season);
            }

            DB::afterCommit(function () use ($poolIds): void {
                $poolIds->each(fn (int $poolId) => Cache::forget(BuildPoolLeaderboard::cacheKey($poolId)));
            });
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        SeasonEventResult::query()
            ->whereKey($this->seasonEventResultId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        Log::error('Official event result finalization failed.', [
            'season_event_result_id' => $this->seasonEventResultId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
