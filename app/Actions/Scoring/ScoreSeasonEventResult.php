<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use App\Models\DraftPick;
use App\Models\PointEntry;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\SeasonEventResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoreSeasonEventResult
{
    public function __construct(private CalculateEventScores $calculateEventScores) {}

    public function handle(SeasonEventResult $result): void
    {
        $result->loadMissing('event.poolEvents', 'options.houseguest', 'supersedes');

        $result->event->poolEvents()
            ->where('is_active', true)
            ->with('pool')
            ->each(fn (PoolEvent $poolEvent) => $this->handleForPoolEvent($result, $poolEvent));
    }

    public function handleForPoolEvent(SeasonEventResult $result, PoolEvent $poolEvent): void
    {
        $result->loadMissing('event', 'options.houseguest', 'supersedes');
        if ($poolEvent->season_event_id !== $result->season_event_id || ! $poolEvent->is_active) {
            return;
        }

        if ($result->supersedes !== null) {
            $this->reverseLegacyAdjustments($poolEvent, $result);
            $this->reversePreviousScore($poolEvent, $result->supersedes, $result);
        }

        $houseguestIds = $result->options->pluck('houseguest_id')->filter()->values();
        $rosterNamesByMember = collect();
        if (in_array($poolEvent->mode, [EventMode::Roster, EventMode::Hybrid], true) && $houseguestIds->isNotEmpty()) {
            $rosterNamesByMember = DraftPick::query()
                ->where('pool_id', $poolEvent->pool_id)
                ->whereIn('houseguest_id', $houseguestIds)
                ->with('houseguest')
                ->get()
                ->groupBy('pool_member_id')
                ->map(fn (Collection $picks): Collection => $picks->pluck('houseguest.name')->filter()->values());
        }

        $predictions = collect();
        if (in_array($poolEvent->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
            $predictions = $poolEvent->predictions()
                ->whereIn('status', ['submitted', 'locked'])
                ->with('options')
                ->get()
                ->map(fn (PoolEventPrediction $prediction): array => [
                    'prediction_id' => $prediction->id,
                    'pool_member_id' => $prediction->pool_member_id,
                    'selected_option_ids' => $prediction->options->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                ]);
        }

        $entries = $this->calculateEventScores->handle(
            $poolEvent->mode,
            $poolEvent->scoring_config,
            $rosterNamesByMember,
            $predictions,
            $result->options->pluck('id'),
        );

        $entries->each(function (array $entry) use ($poolEvent, $result): void {
            $idempotencyKey = match ($entry['type']) {
                'roster' => "canonical-result:{$result->id}:pool-event:{$poolEvent->id}:member:{$entry['pool_member_id']}:roster",
                'prediction' => "canonical-result:{$result->id}:pool-event:{$poolEvent->id}:prediction:{$entry['prediction_id']}",
                default => "canonical-result:{$result->id}:pool-event:{$poolEvent->id}:member:{$entry['pool_member_id']}:negative-floor",
            };

            PointEntry::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'pool_member_id' => $entry['pool_member_id'],
                    'pool_event_id' => $poolEvent->id,
                    'season_event_result_id' => $result->id,
                    'pool_event_prediction_id' => $entry['prediction_id'],
                    'type' => $entry['type'],
                    'points' => $entry['points'],
                    'reason' => $entry['reason'],
                ],
            );
        });
    }

    public function reconcilePoolEvent(PoolEvent $poolEvent): void
    {
        DB::transaction(function () use ($poolEvent): void {
            $poolEvent = PoolEvent::query()->lockForUpdate()->findOrFail($poolEvent->id);

            SeasonEventResult::query()
                ->where('season_event_id', $poolEvent->season_event_id)
                ->whereIn('status', ['pending', 'published', 'failed'])
                ->with(['event', 'options.houseguest', 'supersedes'])
                ->orderBy('version')
                ->each(fn (SeasonEventResult $result) => $this->handleForPoolEvent($result, $poolEvent));
        }, attempts: 3);
    }

    private function reversePreviousScore(PoolEvent $poolEvent, SeasonEventResult $previous, SeasonEventResult $current): void
    {
        PointEntry::query()
            ->where('pool_event_id', $poolEvent->id)
            ->where('season_event_result_id', $previous->id)
            ->whereNull('reverses_point_entry_id')
            ->each(function (PointEntry $entry) use ($poolEvent, $current): void {
                PointEntry::query()->firstOrCreate(
                    ['idempotency_key' => "canonical-result:{$current->id}:reversal:{$entry->id}"],
                    [
                        'pool_member_id' => $entry->pool_member_id,
                        'pool_event_id' => $poolEvent->id,
                        'season_event_result_id' => $current->id,
                        'pool_event_prediction_id' => $entry->pool_event_prediction_id,
                        'reverses_point_entry_id' => $entry->id,
                        'type' => 'reversal',
                        'points' => -$entry->points,
                        'reason' => __('Official result correction'),
                    ],
                );
            });
    }

    private function reverseLegacyAdjustments(PoolEvent $poolEvent, SeasonEventResult $current): void
    {
        $poolEventIds = PoolEvent::query()
            ->where('pool_id', $poolEvent->pool_id)
            ->whereHas('seasonEvent', fn ($query) => $query->where('season_round_id', $current->event->season_round_id))
            ->pluck('id');

        PointEntry::query()
            ->whereIn('pool_event_id', $poolEventIds)
            ->where('type', 'legacy_adjustment')
            ->where('points', '!=', 0)
            ->each(function (PointEntry $entry) use ($poolEvent, $current): void {
                PointEntry::query()->firstOrCreate(
                    ['idempotency_key' => "legacy-adjustment-reversal:{$entry->id}"],
                    [
                        'pool_member_id' => $entry->pool_member_id,
                        'pool_event_id' => $poolEvent->id,
                        'season_event_result_id' => $current->id,
                        'reverses_point_entry_id' => $entry->id,
                        'type' => 'reversal',
                        'points' => -$entry->points,
                        'reason' => __('Legacy import adjustment retired by the first canonical correction.'),
                    ],
                );
            });
    }
}
