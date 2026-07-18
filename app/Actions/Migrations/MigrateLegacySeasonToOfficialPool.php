<?php

namespace App\Actions\Migrations;

use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Actions\Weeks\LegacyWeekPhaseReader;
use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolCompetitionMode;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Enums\PredictionStatus;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekPhase;
use App\Support\LegacyFlowAuthority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MigrateLegacySeasonToOfficialPool
{
    public function __construct(
        private LegacyWeekPhaseReader $legacyWeekPhaseReader,
        private ScoreSeasonEventResult $scoreSeasonEventResult,
        private LegacyFlowAuthority $legacyFlowAuthority,
    ) {}

    public function handle(Season $season): Pool
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages([
                'migration' => __('Legacy imports are disabled after the canonical flow becomes authoritative.'),
            ]);
        }

        return DB::transaction(function () use ($season): Pool {
            $season = Season::query()->with([
                'houseguests',
                'weeks.phases',
                'weeks.predictions.score',
                'weeks.outcome',
            ])->lockForUpdate()->findOrFail($season->id);
            $seasonPredictions = $season->seasonPredictions()->with(['user', 'score'])->get();
            $participantIds = $season->weeks->flatMap->predictions->pluck('user_id')
                ->merge($seasonPredictions->pluck('user_id'))
                ->unique()
                ->values();
            $owner = User::query()->where('is_admin', true)->first()
                ?? User::query()->whereKey($participantIds->first())->first()
                ?? User::query()->first();

            if ($owner === null) {
                throw ValidationException::withMessages(['season' => __('At least one user is required for the official legacy pool.')]);
            }

            $participantIds = $participantIds->push($owner->id)->unique()->values();
            $requiredMemberCapacity = max(2, $participantIds->count());
            $pool = Pool::query()->firstOrCreate(
                ['legacy_key' => "legacy:season:{$season->id}:pool"],
                [
                    'season_id' => $season->id,
                    'owner_id' => $owner->id,
                    'name' => __('Official predictions — :season', ['season' => $season->name]),
                    'description' => __('Imported from the legacy prediction flow.'),
                    'invite_code' => Str::upper('LEGACY'.str_pad((string) $season->id, 4, '0', STR_PAD_LEFT)),
                    'timezone' => config('app.timezone'),
                    'status' => PoolStatus::Active,
                    'competition_mode' => PoolCompetitionMode::PredictionOnly,
                    'max_members' => $requiredMemberCapacity,
                    'picks_per_member' => 1,
                    'exclusive_draft' => false,
                    'registrations_closed_at' => now(),
                ],
            );
            if ($pool->max_members < $requiredMemberCapacity) {
                $pool->update(['max_members' => $requiredMemberCapacity]);
            }

            $members = $this->members($pool, $participantIds, $owner);

            foreach ($season->weeks->sortBy('number') as $week) {
                $round = SeasonRound::query()->updateOrCreate(
                    ['legacy_key' => "legacy:week:{$week->id}"],
                    [
                        'season_id' => $season->id,
                        'name' => filled($week->name)
                            ? $week->name
                            : __('Week :number', ['number' => $week->number]),
                        'position' => $week->number,
                        'status' => $week->outcome ? 'published' : ($week->isLocked() ? 'locked' : 'open'),
                        'starts_at' => $week->starts_at,
                        'ends_at' => $week->ends_at ?? $week->auto_lock_at,
                    ],
                );

                foreach ($week->phases as $phase) {
                    foreach ($this->phaseEventDefinitions($phase) as $definition) {
                        $event = $this->phaseEvent(
                            $season,
                            $round,
                            $phase,
                            $definition,
                            $week->outcome !== null,
                            $week->isLocked(),
                            $week->starts_at,
                            $week->auto_lock_at,
                        );
                        $poolEvent = $this->poolEvent($pool, $event, $definition['maximum'], $definition['points']);

                        foreach ($week->predictions as $prediction) {
                            $member = $members->get($prediction->user_id);
                            if ($member === null) {
                                continue;
                            }

                            $payload = is_array($prediction->phase_picks) && $prediction->phase_picks !== []
                                ? $prediction->phase_picks
                                : $this->legacyWeekPhaseReader->legacyPayloadForModel($prediction, $week->phases);
                            $entry = $this->legacyWeekPhaseReader->payloadByPhaseId($payload)[$phase->id] ?? [];
                            $this->prediction(
                                $poolEvent,
                                $member,
                                "legacy:prediction:{$prediction->id}:phase:{$phase->id}:{$definition['key']}",
                                $this->selectionOptionIds($event, $entry, $definition),
                                $prediction->confirmed_at,
                                $week->isLocked() ? ($week->locked_at ?? $week->auto_lock_at) : null,
                                $prediction->created_at,
                            );
                        }

                        if ($week->outcome !== null) {
                            $payload = is_array($week->outcome->phase_results) && $week->outcome->phase_results !== []
                                ? $week->outcome->phase_results
                                : $this->legacyWeekPhaseReader->legacyPayloadForModel($week->outcome, $week->phases);
                            $entry = $this->legacyWeekPhaseReader->payloadByPhaseId($payload)[$phase->id] ?? [];
                            $result = $this->result(
                                $event,
                                "legacy:outcome:{$week->outcome->id}:phase:{$phase->id}:{$definition['key']}",
                                $this->selectionOptionIds($event, $entry, $definition),
                                $week->outcome->last_admin_edited_by_user_id ?? $owner->id,
                                $week->outcome->updated_at,
                            );
                            $this->scoreSeasonEventResult->handleForPoolEvent($result, $poolEvent);
                        }
                    }
                }

                $this->reconcileWeekScoreAdjustments($week, $round, $pool, $members);
            }

            $this->seasonPredictions($season, $pool, $members, $seasonPredictions, $owner);

            return $pool->fresh(['members', 'poolEvents']);
        }, attempts: 3);
    }

    /** @return Collection<int, PoolMember> */
    private function members(Pool $pool, Collection $userIds, User $owner): Collection
    {
        foreach ($userIds as $userId) {
            PoolMember::query()->updateOrCreate(
                ['pool_id' => $pool->id, 'user_id' => $userId],
                [
                    'role' => $userId === $owner->id ? PoolMemberRole::Owner : PoolMemberRole::Member,
                    'status' => PoolMemberStatus::Active,
                    'draft_position' => null,
                    'joined_at' => now(),
                ],
            );
        }

        return $pool->members()->get()->keyBy('user_id');
    }

    /**
     * @param  array{key:string,name:string,maximum:int,points:int,answer_source:AnswerSource,position:int}  $definition
     */
    private function phaseEvent(Season $season, SeasonRound $round, WeekPhase $phase, array $definition, bool $published, bool $locked, mixed $opensAt, mixed $locksAt): SeasonEvent
    {
        $targetStatus = $published ? EventStatus::Published : ($locked ? EventStatus::Locked : EventStatus::Open);
        $event = SeasonEvent::query()->firstOrNew([
            'legacy_key' => "legacy:week-phase:{$phase->id}:{$definition['key']}",
        ]);
        $event->fill([
            'season_round_id' => $round->id,
            'name' => $definition['name'],
            'question' => $definition['name'],
            'answer_source' => $definition['answer_source'],
            'status' => $event->exists ? $event->status : EventStatus::Draft,
            'position' => ($phase->position * 10) + $definition['position'],
            'opens_at' => $opensAt,
            'locks_at' => $locksAt,
            'result_min_selections' => 0,
            'result_max_selections' => max(1, $definition['maximum']),
            'scoring_config' => $this->legacyScoringConfig($definition['points']),
        ]);
        $event->save();
        $this->options($event, $season, $definition['answer_source']);

        if ($event->status !== $targetStatus) {
            $event->update(['status' => $targetStatus]);
        }

        return $event->fresh('options');
    }

    private function poolEvent(Pool $pool, SeasonEvent $event, int $maximum, int $pointsPerCorrect = 1): PoolEvent
    {
        $poolEvent = PoolEvent::query()->firstOrNew([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
        ]);
        $poolEvent->fill([
            'local_event_id' => null,
            'mode' => EventMode::Prediction,
            'is_active' => true,
            'visibility' => 'after_lock',
            'prediction_min_selections' => 0,
            'prediction_max_selections' => max(1, $maximum),
            'scoring_config' => $this->legacyScoringConfig($pointsPerCorrect),
            'rules_customized_at' => $poolEvent->rules_customized_at ?? now(),
        ]);
        $poolEvent->save();

        return $poolEvent;
    }

    private function options(SeasonEvent $event, Season $season, AnswerSource $answerSource = AnswerSource::Houseguests): void
    {
        if ($event->options_locked_at !== null) {
            return;
        }

        SeasonEventOption::withoutEvents(function () use ($answerSource, $event, $season): void {
            if ($answerSource === AnswerSource::Boolean) {
                $event->options()->updateOrCreate(['value' => 'boolean:true'], ['label' => __('Yes'), 'position' => 1]);
                $event->options()->updateOrCreate(['value' => 'boolean:false'], ['label' => __('No'), 'position' => 2]);
            } else {
                foreach ($season->houseguests as $position => $houseguest) {
                    $event->options()->updateOrCreate(
                        ['value' => 'houseguest:'.$houseguest->id],
                        [
                            'houseguest_id' => $houseguest->id,
                            'label' => $houseguest->name,
                            'position' => $position + 1,
                        ],
                    );
                }
            }
        });
        $event->update(['options_locked_at' => now()]);
    }

    /** @param list<int> $optionIds */
    private function prediction(PoolEvent $poolEvent, PoolMember $member, string $key, array $optionIds, mixed $submittedAt, mixed $lockedAt, mixed $createdAt): void
    {
        $prediction = PoolEventPrediction::query()->updateOrCreate(
            ['legacy_key' => $key],
            [
                'pool_event_id' => $poolEvent->id,
                'pool_member_id' => $member->id,
                'status' => $submittedAt === null ? PredictionStatus::Draft : ($lockedAt === null ? PredictionStatus::Submitted : PredictionStatus::Locked),
                'submitted_at' => $submittedAt,
                'locked_at' => $lockedAt,
            ],
        );
        $prediction->forceFill(['created_at' => $createdAt])->saveQuietly();
        $prediction->options()->sync($optionIds);
    }

    /** @param list<int> $optionIds */
    private function result(SeasonEvent $event, string $key, array $optionIds, int $actorId, mixed $publishedAt): SeasonEventResult
    {
        $optionIds = collect($optionIds)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();
        $latestImportedResult = SeasonEventResult::query()
            ->where('season_event_id', $event->id)
            ->where(function ($query) use ($key): void {
                $query->where('legacy_key', $key)
                    ->orWhere('legacy_key', 'like', $key.':revision:%');
            })
            ->with('options:id')
            ->orderByDesc('version')
            ->first();

        if ($latestImportedResult !== null
            && $latestImportedResult->options->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all() === $optionIds->all()) {
            return $latestImportedResult->fresh(['event', 'options.houseguest', 'supersedes']);
        }

        $previousResult = $event->results()->first();
        $version = ($previousResult?->version ?? 0) + 1;
        $result = $event->results()->create([
            'legacy_key' => $latestImportedResult === null ? $key : $key.':revision:'.$version,
            'created_by' => User::query()->whereKey($actorId)->exists() ? $actorId : null,
            'supersedes_id' => $previousResult?->id,
            'version' => $version,
            'status' => 'draft',
            'correction_reason' => $previousResult === null ? null : __('Official result correction'),
            'published_at' => null,
        ]);
        $result->options()->sync($optionIds->all());
        $result->update([
            'status' => 'published',
            'published_at' => $publishedAt,
        ]);

        return $result->fresh(['event', 'options.houseguest', 'supersedes']);
    }

    /**
     * @return list<array{key:string,name:string,maximum:int,points:int,answer_source:AnswerSource,position:int}>
     */
    private function phaseEventDefinitions(WeekPhase $phase): array
    {
        $config = $this->legacyWeekPhaseReader->normalizeConfig($phase->type, $phase->config ?? []);
        $labels = $this->legacyWeekPhaseReader->selectionLabels($phase->type);
        $definitions = [];
        $position = 1;

        if ($phase->type === LegacyWeekPhaseReader::TYPE_VETO) {
            $definitions[] = [
                'key' => 'veto_used',
                'name' => __('Veto used'),
                'maximum' => 1,
                'points' => 1,
                'answer_source' => AnswerSource::Boolean,
                'position' => $position++,
            ];
        }

        foreach ($this->legacyWeekPhaseReader->selectionListKeys($phase->type) as $listKey => $countKey) {
            $maximum = (int) ($config[$countKey] ?? 0);
            if ($maximum === 0) {
                continue;
            }

            $definitions[] = [
                'key' => $listKey,
                'name' => $labels[$listKey] ?? $this->legacyWeekPhaseReader->phaseTypeLabel($phase->type),
                'maximum' => $maximum,
                'points' => 1,
                'answer_source' => AnswerSource::Houseguests,
                'position' => $position++,
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{key:string,name:string,maximum:int,points:int,answer_source:AnswerSource,position:int}  $definition
     * @return list<int>
     */
    private function selectionOptionIds(SeasonEvent $event, array $entry, array $definition): array
    {
        if ($definition['answer_source'] === AnswerSource::Boolean) {
            $value = $this->legacyWeekPhaseReader->vetoUsage($entry);

            return $value === null
                ? []
                : $event->options()->where('value', $value ? 'boolean:true' : 'boolean:false')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $houseguestIds = $this->legacyWeekPhaseReader->normalizeIdList($entry[$definition['key']] ?? []);

        return $event->options()
            ->whereIn('houseguest_id', $houseguestIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param Collection<int, PoolMember> $members */
    private function reconcileWeekScoreAdjustments(Week $week, SeasonRound $round, Pool $pool, Collection $members): void
    {
        $poolEventIds = PoolEvent::query()
            ->where('pool_id', $pool->id)
            ->whereHas('seasonEvent', fn ($query) => $query->where('season_round_id', $round->id))
            ->pluck('id');
        $firstPoolEventId = $poolEventIds->first();

        foreach ($week->predictions as $prediction) {
            $score = $prediction->score;
            $member = $members->get($prediction->user_id);
            if ($score === null || $member === null || $prediction->confirmed_at === null || $firstPoolEventId === null) {
                continue;
            }

            $canonicalPoints = (int) PointEntry::query()
                ->where('pool_member_id', $member->id)
                ->whereIn('pool_event_id', $poolEventIds)
                ->where('type', '!=', 'legacy_adjustment')
                ->where('idempotency_key', '!=', "legacy:prediction-score:{$score->id}")
                ->sum('points');
            $difference = $score->points - $canonicalPoints;
            $this->appendLegacyScoreAdjustment(
                $member,
                $firstPoolEventId,
                "legacy:prediction-score:{$score->id}",
                "legacy:prediction-score-adjustment:{$score->id}",
                $difference,
                __('Imported legacy week score reconciliation: :week', ['week' => $week->name]),
                $score->calculated_at ?? $score->created_at,
                $score->updated_at,
            );
        }
    }

    private function seasonPredictions(Season $season, Pool $pool, Collection $members, Collection $predictions, User $owner): void
    {
        if ($predictions->isEmpty()) {
            return;
        }

        $existingRound = SeasonRound::query()->where('legacy_key', "legacy:season:{$season->id}:predictions")->first();
        $position = $existingRound?->position ?? (((int) $season->canonicalRounds()->max('position')) + 1);
        $round = SeasonRound::query()->updateOrCreate(
            ['legacy_key' => "legacy:season:{$season->id}:predictions"],
            [
                'season_id' => $season->id,
                'name' => __('Season predictions'),
                'position' => $position,
                'status' => $season->winner_houseguest_id ? 'published' : 'open',
                'starts_at' => $season->prediction_opens_at,
                'ends_at' => $season->prediction_locks_at,
            ],
        );
        $definitions = [
            'winner' => ['name' => __('Winner'), 'max' => 1, 'points' => 16, 'prediction' => 'winner_houseguest_id', 'result' => [$season->winner_houseguest_id]],
            'first-evicted' => ['name' => __('First evicted'), 'max' => 1, 'points' => 16, 'prediction' => 'first_evicted_houseguest_id', 'result' => [$season->first_evicted_houseguest_id]],
            'top-six' => ['name' => __('Top 6'), 'max' => 6, 'points' => 2, 'prediction' => 'top_6_houseguest_ids', 'result' => $season->top_6_houseguest_ids ?? []],
        ];

        foreach ($definitions as $index => $definition) {
            $targetStatus = array_filter($definition['result']) !== [] ? EventStatus::Published : EventStatus::Open;
            $event = SeasonEvent::query()->firstOrNew([
                'legacy_key' => "legacy:season:{$season->id}:{$index}",
            ]);
            $event->fill([
                'season_round_id' => $round->id,
                'name' => $definition['name'],
                'question' => $definition['name'],
                'answer_source' => AnswerSource::Houseguests,
                'status' => $event->exists ? $event->status : EventStatus::Draft,
                'position' => array_search($index, array_keys($definitions), true) + 1,
                'opens_at' => $season->prediction_opens_at,
                'locks_at' => $season->prediction_locks_at,
                'result_min_selections' => 0,
                'result_max_selections' => $definition['max'],
                'scoring_config' => $this->legacyScoringConfig($definition['points']),
            ]);
            $event->save();
            $this->options($event, $season);

            if ($event->status !== $targetStatus) {
                $event->update(['status' => $targetStatus]);
            }

            $poolEvent = $this->poolEvent($pool, $event, $definition['max'], $definition['points']);

            foreach ($predictions as $prediction) {
                $value = $prediction->{$definition['prediction']};
                $houseguestIds = is_array($value) ? $value : array_filter([$value]);
                $this->prediction(
                    $poolEvent,
                    $members->get($prediction->user_id),
                    "legacy:season-prediction:{$prediction->id}:{$index}",
                    $event->options()->whereIn('houseguest_id', array_map('intval', $houseguestIds))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $prediction->confirmed_at,
                    $season->predictionsAreLocked() ? $season->prediction_locks_at : null,
                    $prediction->created_at,
                );
            }

            $actualIds = array_values(array_filter($definition['result']));
            if ($actualIds !== []) {
                $result = $this->result(
                    $event,
                    "legacy:season-result:{$season->id}:{$index}",
                    $event->options()->whereIn('houseguest_id', array_map('intval', $actualIds))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $owner->id,
                    now(),
                );
                $this->scoreSeasonEventResult->handleForPoolEvent($result, $poolEvent);
            }
        }

        $poolEventIds = PoolEvent::query()
            ->where('pool_id', $pool->id)
            ->whereHas('seasonEvent', fn ($query) => $query->where('season_round_id', $round->id))
            ->pluck('id');
        $firstPoolEventId = $poolEventIds->first();

        foreach ($predictions as $prediction) {
            $score = $prediction->score;
            $member = $members->get($prediction->user_id);
            if ($score === null || $member === null || $prediction->confirmed_at === null || $firstPoolEventId === null) {
                continue;
            }

            $canonicalPoints = (int) PointEntry::query()
                ->where('pool_member_id', $member->id)
                ->whereIn('pool_event_id', $poolEventIds)
                ->where('type', '!=', 'legacy_adjustment')
                ->where('idempotency_key', '!=', "legacy:season-prediction-score:{$score->id}")
                ->sum('points');
            $difference = $score->points - $canonicalPoints;
            $this->appendLegacyScoreAdjustment(
                $member,
                $firstPoolEventId,
                "legacy:season-prediction-score:{$score->id}",
                "legacy:season-prediction-score-adjustment:{$score->id}",
                $difference,
                __('Imported legacy season score reconciliation.'),
                $score->calculated_at ?? $score->created_at,
                $score->updated_at,
            );
        }
    }

    /** @return array<string, mixed> */
    private function legacyScoringConfig(int $pointsPerCorrect): array
    {
        return [
            'owner' => ['points_per_match' => 0],
            'prediction' => [
                'points_per_correct' => $pointsPerCorrect,
                'exact_match_bonus' => 0,
                'wrong_answer_penalty' => 0,
            ],
            'allow_negative' => false,
            'legacy_import' => true,
        ];
    }

    private function appendLegacyScoreAdjustment(
        PoolMember $member,
        int $poolEventId,
        string $legacyEntryKey,
        string $adjustmentKey,
        int $targetAdjustment,
        string $reason,
        mixed $createdAt,
        mixed $updatedAt,
    ): void {
        $legacyEntry = PointEntry::query()
            ->where('idempotency_key', $legacyEntryKey)
            ->lockForUpdate()
            ->first();

        if ($legacyEntry !== null
            && ! PointEntry::query()->where('idempotency_key', $legacyEntryKey.':reversal')->exists()) {
            $reversal = new PointEntry([
                'pool_member_id' => $member->id,
                'pool_event_id' => $poolEventId,
                'reverses_point_entry_id' => $legacyEntry->id,
                'type' => 'legacy_adjustment',
                'points' => -$legacyEntry->points,
                'reason' => $reason,
                'idempotency_key' => $legacyEntryKey.':reversal',
            ]);
            $reversal->forceFill(['created_at' => $createdAt, 'updated_at' => $updatedAt])->save();
        }

        $adjustments = PointEntry::query()
            ->where('pool_member_id', $member->id)
            ->where(function ($query) use ($adjustmentKey): void {
                $query->where('idempotency_key', $adjustmentKey)
                    ->orWhere('idempotency_key', 'like', $adjustmentKey.':revision:%');
            })
            ->lockForUpdate()
            ->get();
        $delta = $targetAdjustment - (int) $adjustments->sum('points');

        if ($delta === 0) {
            return;
        }

        $revisionKey = $adjustments->isEmpty()
            ? $adjustmentKey
            : $adjustmentKey.':revision:'.($adjustments->count() + 1);
        $adjustment = new PointEntry([
            'pool_member_id' => $member->id,
            'pool_event_id' => $poolEventId,
            'type' => 'legacy_adjustment',
            'points' => $delta,
            'reason' => $reason,
            'idempotency_key' => $revisionKey,
        ]);
        $adjustment->forceFill(['created_at' => $createdAt, 'updated_at' => $updatedAt])->save();
    }
}
