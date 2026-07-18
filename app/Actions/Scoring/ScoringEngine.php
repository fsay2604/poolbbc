<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use App\Models\DraftPick;
use App\Models\EventResult;
use App\Models\PointEntry;
use Illuminate\Support\Collection;

class ScoringEngine
{
    public function __construct(private CalculateEventScores $calculateEventScores) {}

    public function score(EventResult $result): void
    {
        $result->loadMissing('event.options.houseguest', 'event.pool', 'options.houseguest');
        $event = $result->event;
        $houseguestIds = $result->options->pluck('houseguest_id')->filter()->values();
        $competitionMemberIds = $event->pool->competitionMembers()->pluck('id');

        $rosterNamesByMember = collect();
        if (in_array($event->mode, [EventMode::Roster, EventMode::Hybrid], true) && $houseguestIds->isNotEmpty()) {
            $rosterNamesByMember = DraftPick::query()
                ->where('pool_id', $event->pool_id)
                ->whereIn('pool_member_id', $competitionMemberIds)
                ->whereIn('houseguest_id', $houseguestIds)
                ->with('houseguest')
                ->get()
                ->groupBy('pool_member_id')
                ->map(fn (Collection $picks): Collection => $picks->pluck('houseguest.name')->filter()->values());
        }

        $predictions = collect();
        if (in_array($event->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
            $predictions = $event->predictions()
                ->whereIn('pool_member_id', $competitionMemberIds)
                ->whereIn('status', ['submitted', 'locked'])
                ->with('options')
                ->get()
                ->map(fn ($prediction): array => [
                    'prediction_id' => $prediction->id,
                    'pool_member_id' => $prediction->pool_member_id,
                    'selected_option_ids' => $prediction->options->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                ]);
        }

        $entries = $this->calculateEventScores->handle(
            $event->mode,
            $event->scoring_config,
            $rosterNamesByMember,
            $predictions,
            $result->options->pluck('id'),
        );

        $entries->each(function (array $entry) use ($result): void {
            $idempotencyKey = match ($entry['type']) {
                'roster' => "result:{$result->id}:member:{$entry['pool_member_id']}:roster",
                'prediction' => "result:{$result->id}:prediction:{$entry['prediction_id']}",
                default => "result:{$result->id}:member:{$entry['pool_member_id']}:negative-floor",
            };

            PointEntry::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'pool_member_id' => $entry['pool_member_id'],
                    'event_id' => $result->event_id,
                    'event_result_id' => $result->id,
                    'event_prediction_id' => $entry['prediction_id'],
                    'type' => $entry['type'],
                    'points' => $entry['points'],
                    'reason' => $entry['reason'],
                ],
            );
        });
    }
}
