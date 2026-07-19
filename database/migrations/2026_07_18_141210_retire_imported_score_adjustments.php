<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_OBSERVATION_GAP_HOURS = 36;

    private const MINIMUM_OBSERVATION_DAYS = 14;

    /** @var list<string> */
    private const RETIRED_TABLES = [
        'season_prediction_scores',
        'prediction_scores',
        'week_phases',
        'week_outcomes',
        'predictions',
        'season_predictions',
        'weeks',
    ];

    /** @var list<string> */
    private const LEGACY_KEY_TABLES = [
        'season_event_results',
        'pool_event_predictions',
        'season_events',
        'season_rounds',
        'pools',
    ];

    /** @var list<string> */
    private const RETIRED_SEASON_COLUMNS = [
        'prediction_opens_at',
        'prediction_locks_at',
        'winner_houseguest_id',
        'first_evicted_houseguest_id',
        'top_6_houseguest_ids',
    ];

    public function up(): void
    {
        $this->assertRetirementReady();

        DB::transaction(function (): void {
            $adjustments = DB::table('point_entries')
                ->where('type', 'legacy_adjustment')
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'pool_member_id',
                    'pool_event_id',
                    'points',
                    'created_at',
                    'updated_at',
                ]);

            $plans = [];

            foreach ($adjustments as $adjustment) {
                $plan = $this->planAdjustmentRetirement($adjustment);

                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }

            foreach ($plans as $plan) {
                $adjustment = $plan['adjustment'];
                $timestamp = $adjustment->updated_at ?? $adjustment->created_at ?? now();

                DB::table('point_entries')->insert([
                    [
                        'pool_member_id' => $adjustment->pool_member_id,
                        'pool_event_id' => $adjustment->pool_event_id,
                        'reverses_point_entry_id' => $adjustment->id,
                        'type' => 'reversal',
                        'points' => -((int) $adjustment->points),
                        'reason' => 'Historical score reconciliation retired.',
                        'idempotency_key' => $plan['reversal_key'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    [
                        'pool_member_id' => $adjustment->pool_member_id,
                        'pool_event_id' => $adjustment->pool_event_id,
                        'reverses_point_entry_id' => null,
                        'type' => 'round_reconciliation',
                        'points' => (int) $adjustment->points,
                        'reason' => 'Canonical round score reconciliation.',
                        'idempotency_key' => $plan['reconciliation_key'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                ]);
            }

            foreach ($plans as $plan) {
                if ($this->ledgerTotal($plan['adjustment']) !== $plan['total_before']) {
                    throw new RuntimeException(
                        "Score reconciliation retirement changed the ledger total for source entry [{$plan['adjustment']->id}].",
                    );
                }
            }
        }, attempts: 3);
    }

    public function down(): void
    {
        throw new RuntimeException('Imported score-adjustment retirement is append-only and cannot be rolled back. Apply a forward correction migration instead.');
    }

    private function assertRetirementReady(): void
    {
        if (! $this->hasRetirementFootprint()) {
            return;
        }

        foreach ([
            'canonical_flow_authorities',
            'legacy_shadow_observations',
            'legacy_backup_restore_attestations',
        ] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                $this->notReady("required evidence table [{$tableName}] is missing");
            }
        }

        $authority = DB::table('canonical_flow_authorities')
            ->where('marker', 'canonical')
            ->first();

        if ($authority === null || $authority->activated_at === null) {
            $this->notReady('the immutable canonical authority marker is missing');
        }

        $observations = DB::table('legacy_shadow_observations')
            ->where('authority_marker', 'canonical')
            ->where('environment', app()->environment())
            ->orderBy('observed_at')
            ->get();

        if ($observations->count() < 2) {
            $this->notReady('fewer than two shadow observations exist for the current environment');
        }

        if ($observations->contains(fn (object $observation): bool => (int) $observation->unapproved_differences !== 0)) {
            $this->notReady('shadow observations contain an unapproved difference');
        }

        $observationStartedAt = Carbon::parse($observations->first()->observed_at);
        $observationCompletedAt = $observationStartedAt->copy()->addDays(self::MINIMUM_OBSERVATION_DAYS);
        $lastObservedAt = Carbon::parse($observations->last()->observed_at);

        if ($lastObservedAt->isAfter(now())) {
            $this->notReady('shadow observation history contains a future timestamp');
        }

        if (Carbon::parse($authority->activated_at)->isAfter($observationStartedAt)) {
            $this->notReady('shadow observation began before canonical authority was activated');
        }

        if (now()->isBefore($observationCompletedAt) || $lastObservedAt->isBefore($observationCompletedAt)) {
            $this->notReady('the minimum 14-day shadow observation window is incomplete');
        }

        if ($lastObservedAt->isBefore(now()->subHours(self::MAX_OBSERVATION_GAP_HOURS))) {
            $this->notReady('the final zero-difference shadow observation is stale');
        }

        for ($index = 1; $index < $observations->count(); $index++) {
            $previous = Carbon::parse($observations[$index - 1]->observed_at);
            $current = Carbon::parse($observations[$index]->observed_at);

            if ($previous->diffInHours($current) > self::MAX_OBSERVATION_GAP_HOURS) {
                $this->notReady('shadow observation history contains a gap greater than 36 hours');
            }
        }

        $attestations = DB::table('legacy_backup_restore_attestations')
            ->where('authority_marker', 'canonical')
            ->where('environment', app()->environment())
            ->where('verified_at', '>=', $observationCompletedAt)
            ->orderByDesc('verified_at')
            ->get();

        if (! $attestations->contains(fn (object $attestation): bool => $this->validAttestation($attestation))) {
            $this->notReady('no verified backup restoration attestation exists after observation completion');
        }
    }

    private function hasRetirementFootprint(): bool
    {
        foreach (array_merge(
            self::RETIRED_TABLES,
            ['canonical_flow_authorities', 'legacy_shadow_observations', 'legacy_backup_restore_attestations'],
        ) as $tableName) {
            if (Schema::hasTable($tableName) && DB::table($tableName)->exists()) {
                return true;
            }
        }

        if (Schema::hasTable('point_entries')
            && DB::table('point_entries')->where('type', 'legacy_adjustment')->exists()) {
            return true;
        }

        foreach (self::LEGACY_KEY_TABLES as $tableName) {
            if (Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, 'legacy_key')
                && DB::table($tableName)->whereNotNull('legacy_key')->exists()) {
                return true;
            }
        }

        $seasonColumns = array_values(array_filter(
            self::RETIRED_SEASON_COLUMNS,
            fn (string $column): bool => Schema::hasTable('seasons') && Schema::hasColumn('seasons', $column),
        ));

        if ($seasonColumns === []) {
            return false;
        }

        return DB::table('seasons')
            ->where(function ($query) use ($seasonColumns): void {
                foreach ($seasonColumns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->exists();
    }

    private function validAttestation(object $attestation): bool
    {
        $checks = is_string($attestation->integrity_checks)
            ? json_decode($attestation->integrity_checks, true)
            : $attestation->integrity_checks;

        if (! is_array($checks)
            || ($checks['schema'] ?? false) !== true
            || ($checks['row_counts'] ?? false) !== true
            || ($checks['application_smoke'] ?? false) !== true
            || $attestation->verified_at === null
            || Carbon::parse($attestation->verified_at)->isAfter(now())
            || ! is_string($attestation->evidence_path)
            || ! File::isFile($attestation->evidence_path)
            || ! File::isReadable($attestation->evidence_path)
            || ! is_string($attestation->evidence_sha256)) {
            return false;
        }

        return hash_equals(
            strtolower($attestation->evidence_sha256),
            hash('sha256', File::get($attestation->evidence_path)),
        );
    }

    /**
     * @return array{
     *     adjustment: object,
     *     reversal_key: string,
     *     reconciliation_key: string,
     *     total_before: int
     * }|null
     */
    private function planAdjustmentRetirement(object $adjustment): ?array
    {
        $reversalKey = "round-reconciliation:source-entry:{$adjustment->id}:retirement";
        $reconciliationKey = "round-reconciliation:source-entry:{$adjustment->id}:canonical";
        $migrationReversal = DB::table('point_entries')->where('idempotency_key', $reversalKey)->first();
        $reconciliation = DB::table('point_entries')->where('idempotency_key', $reconciliationKey)->first();
        $reversals = DB::table('point_entries')
            ->where('reverses_point_entry_id', $adjustment->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reversals->isEmpty()) {
            if ($migrationReversal !== null || $reconciliation !== null) {
                throw new RuntimeException("Source entry [{$adjustment->id}] has an incomplete deterministic retirement pair.");
            }

            if ($adjustment->pool_event_id === null) {
                throw new RuntimeException("Source entry [{$adjustment->id}] cannot be mapped to a canonical round.");
            }

            return [
                'adjustment' => $adjustment,
                'reversal_key' => $reversalKey,
                'reconciliation_key' => $reconciliationKey,
                'total_before' => $this->ledgerTotal($adjustment),
            ];
        }

        if ($reversals->count() !== 1 || (int) $reversals->first()->points !== -((int) $adjustment->points)) {
            throw new RuntimeException("Source entry [{$adjustment->id}] has an ambiguous or partial reversal history.");
        }

        $reversal = $reversals->first();

        if ($reversal->idempotency_key !== $reversalKey) {
            if ($migrationReversal !== null || $reconciliation !== null) {
                throw new RuntimeException("Source entry [{$adjustment->id}] mixes correction and retirement lifecycles.");
            }

            return null;
        }

        if ($reconciliation === null
            || (int) $reversal->pool_member_id !== (int) $adjustment->pool_member_id
            || (int) $reversal->pool_event_id !== (int) $adjustment->pool_event_id
            || $reversal->type !== 'reversal'
            || (int) $reconciliation->pool_member_id !== (int) $adjustment->pool_member_id
            || (int) $reconciliation->pool_event_id !== (int) $adjustment->pool_event_id
            || $reconciliation->season_event_result_id !== null
            || $reconciliation->reverses_point_entry_id !== null
            || $reconciliation->type !== 'round_reconciliation'
            || (int) $reconciliation->points !== (int) $adjustment->points) {
            throw new RuntimeException("Source entry [{$adjustment->id}] has a corrupt deterministic retirement pair.");
        }

        return null;
    }

    private function ledgerTotal(object $adjustment): int
    {
        return (int) DB::table('point_entries')
            ->where('pool_member_id', $adjustment->pool_member_id)
            ->when(
                $adjustment->pool_event_id === null,
                fn ($query) => $query->whereNull('pool_event_id'),
                fn ($query) => $query->where('pool_event_id', $adjustment->pool_event_id),
            )
            ->sum('points');
    }

    private function notReady(string $reason): never
    {
        throw new RuntimeException("Legacy retirement is blocked: {$reason}. Preserve the historical schema and complete the operational gates first.");
    }
};
