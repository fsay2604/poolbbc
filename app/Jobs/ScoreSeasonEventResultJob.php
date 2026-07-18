<?php

namespace App\Jobs;

use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\SeasonEventResult;
use App\Models\SeasonEventResultScoringReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScoreSeasonEventResultJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public int $seasonEventResultId,
        public int $poolEventId,
    ) {}

    public function uniqueId(): string
    {
        return $this->seasonEventResultId.':'.$this->poolEventId;
    }

    public function handle(ScoreSeasonEventResult $score): void
    {
        DB::transaction(function () use ($score): void {
            $result = SeasonEventResult::query()->lockForUpdate()->findOrFail($this->seasonEventResultId);
            $poolEvent = PoolEvent::query()->lockForUpdate()->findOrFail($this->poolEventId);

            if ($poolEvent->season_event_id !== $result->season_event_id) {
                throw new \LogicException('The scoring job result does not belong to the pool event.');
            }

            if (! in_array($result->status, ['pending', 'failed'], true)) {
                return;
            }

            SeasonEventResultScoringReceipt::query()->firstOrCreate([
                'season_event_result_id' => $result->id,
                'pool_event_id' => $poolEvent->id,
            ]);
            $receipt = SeasonEventResultScoringReceipt::query()
                ->where('season_event_result_id', $result->id)
                ->where('pool_event_id', $poolEvent->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($receipt->completed_at !== null) {
                return;
            }

            if ($result->status === 'failed') {
                $result->update(['status' => 'pending']);
            }

            $pool = Pool::query()->lockForUpdate()->findOrFail($poolEvent->pool_id);
            $score->reconcilePoolEvent($poolEvent);
            $receipt->update(['completed_at' => now()]);
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        SeasonEventResult::query()
            ->whereKey($this->seasonEventResultId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        Log::error('Official event scoring job failed.', [
            'season_event_result_id' => $this->seasonEventResultId,
            'pool_event_id' => $this->poolEventId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
