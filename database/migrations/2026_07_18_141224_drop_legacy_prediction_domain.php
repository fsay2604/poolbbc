<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array{update: string, delete: string, insert: string} */
    private const OFFICIAL_RESULT_TRIGGERS = [
        'update' => 'season_event_results_immutable_update',
        'delete' => 'season_event_results_immutable_delete',
        'insert' => 'season_event_results_published_at_consistent_insert',
    ];

    /** @var array{update: string, delete: string, insert: string} */
    private const RETIREMENT_GUARD_TRIGGERS = [
        'update' => 'season_event_results_retirement_guard_update',
        'delete' => 'season_event_results_retirement_guard_delete',
        'insert' => 'season_event_results_retirement_guard_insert',
    ];

    /** @var list<string> */
    private const RETIREMENT_EVIDENCE_TRIGGERS = [
        'legacy_shadow_observations_immutable_update',
        'legacy_shadow_observations_immutable_delete',
        'legacy_backup_attestations_immutable_update',
        'legacy_backup_attestations_immutable_delete',
        'canonical_flow_authority_valid_insert',
        'canonical_flow_authority_immutable_update',
        'canonical_flow_authority_immutable_delete',
    ];

    public function up(): void
    {
        $this->assertSupportedSchema();
        $this->assertReadinessGateApplied();

        $this->dropRetiredSeasonColumns();
        $this->replaceOfficialResultTriggersAndDropLegacyKey();

        foreach ([
            'pool_event_predictions',
            'season_events',
            'season_rounds',
            'pools',
        ] as $tableName) {
            $this->dropLegacyKey($tableName);
        }

        foreach ([
            'season_prediction_scores',
            'prediction_scores',
            'week_phases',
            'week_outcomes',
            'predictions',
            'season_predictions',
            'weeks',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        $this->dropTriggers(self::RETIREMENT_EVIDENCE_TRIGGERS);
        Schema::dropIfExists('legacy_shadow_observations');
        Schema::dropIfExists('legacy_backup_restore_attestations');
        Schema::dropIfExists('canonical_flow_authorities');
    }

    public function down(): void
    {
        throw new RuntimeException('The retired prediction domain and its provenance metadata cannot be restored by rollback. Restore an archived backup or apply a forward recovery migration.');
    }

    private function assertSupportedSchema(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Legacy retirement is not implemented for the [{$driver}] database driver.");
        }

        foreach ([
            'seasons',
            'season_event_results',
            'pool_event_predictions',
            'season_events',
            'season_rounds',
            'pools',
        ] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                throw new RuntimeException("Canonical table [{$tableName}] is missing; legacy retirement did not start.");
            }
        }
    }

    private function assertReadinessGateApplied(): void
    {
        if (! Schema::hasTable('migrations')
            || ! DB::table('migrations')
                ->where('migration', '2026_07_18_141210_retire_imported_score_adjustments')
                ->exists()) {
            throw new RuntimeException('Legacy retirement readiness and ledger conversion must complete before destructive schema cleanup.');
        }
    }

    private function dropRetiredSeasonColumns(): void
    {
        foreach (['winner_houseguest_id', 'first_evicted_houseguest_id'] as $columnName) {
            if (Schema::hasColumn('seasons', $columnName) && $this->hasForeignKey('seasons', $columnName)) {
                Schema::table('seasons', function (Blueprint $table) use ($columnName): void {
                    $table->dropForeign([$columnName]);
                });
            }

            if (Schema::hasColumn('seasons', $columnName) && Schema::hasIndex('seasons', [$columnName])) {
                Schema::table('seasons', function (Blueprint $table) use ($columnName): void {
                    $table->dropIndex([$columnName]);
                });
            }
        }

        if (Schema::hasColumn('seasons', 'prediction_locks_at')
            && Schema::hasIndex('seasons', ['prediction_locks_at'])) {
            Schema::table('seasons', function (Blueprint $table): void {
                $table->dropIndex(['prediction_locks_at']);
            });
        }

        $columns = array_values(array_filter([
            'prediction_opens_at',
            'prediction_locks_at',
            'winner_houseguest_id',
            'first_evicted_houseguest_id',
            'top_6_houseguest_ids',
        ], fn (string $columnName): bool => Schema::hasColumn('seasons', $columnName)));

        if ($columns !== []) {
            Schema::table('seasons', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function replaceOfficialResultTriggersAndDropLegacyKey(): void
    {
        $this->installOfficialResultTriggers(self::RETIREMENT_GUARD_TRIGGERS);
        $this->dropTriggers(array_values(self::OFFICIAL_RESULT_TRIGGERS));
        $this->dropLegacyKey('season_event_results');
        $this->installOfficialResultTriggers(self::OFFICIAL_RESULT_TRIGGERS);

        foreach (self::OFFICIAL_RESULT_TRIGGERS as $triggerName) {
            if (! $this->triggerExists($triggerName)) {
                throw new RuntimeException("Official-result integrity trigger [{$triggerName}] was not installed.");
            }
        }

        $this->dropTriggers(array_values(self::RETIREMENT_GUARD_TRIGGERS));
    }

    private function dropLegacyKey(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'legacy_key')) {
            return;
        }

        if (Schema::hasIndex($tableName, ['legacy_key'], 'unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['legacy_key']);
            });
        }

        if (Schema::hasColumn($tableName, 'legacy_key')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('legacy_key');
            });
        }
    }

    private function hasForeignKey(string $tableName, string $columnName): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if ($foreignKey['columns'] === [$columnName]) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $triggers */
    private function dropTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /** @param array{update: string, delete: string, insert: string} $triggers */
    private function installOfficialResultTriggers(array $triggers): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->installSqliteOfficialResultTriggers($triggers);

            return;
        }

        $this->installMySqlOfficialResultTriggers($triggers);
    }

    /** @param array{update: string, delete: string, insert: string} $triggers */
    private function installSqliteOfficialResultTriggers(array $triggers): void
    {
        $updateTrigger = $triggers['update'];
        $deleteTrigger = $triggers['delete'];
        $insertTrigger = $triggers['insert'];

        if (! $this->triggerExists($updateTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON season_event_results
                WHEN NOT (
                    (
                        (
                            (OLD.status = 'draft' AND NEW.status IN ('pending', 'published'))
                            OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published'))
                            OR (OLD.status = 'failed' AND NEW.status = 'pending')
                        )
                        AND OLD.id IS NEW.id
                        AND OLD.season_event_id IS NEW.season_event_id
                        AND OLD.created_by IS NEW.created_by
                        AND OLD.supersedes_id IS NEW.supersedes_id
                        AND OLD.version IS NEW.version
                        AND OLD.correction_reason IS NEW.correction_reason
                        AND OLD.created_at IS NEW.created_at
                        AND (
                            (NEW.status = 'published' AND NEW.published_at IS NOT NULL)
                            OR (NEW.status <> 'published' AND NEW.published_at IS NULL)
                        )
                    )
                    OR (
                        OLD.status = 'draft'
                        AND NEW.status = 'draft'
                        AND NEW.published_at IS NULL
                        AND OLD.id IS NEW.id
                        AND OLD.season_event_id IS NEW.season_event_id
                        AND OLD.created_by IS NEW.created_by
                        AND OLD.supersedes_id IS NEW.supersedes_id
                        AND OLD.version IS NEW.version
                        AND OLD.created_at IS NEW.created_at
                    )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Official result history is immutable');
                END
                SQL);
        }

        if (! $this->triggerExists($deleteTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON season_event_results
                BEGIN
                    SELECT RAISE(ABORT, 'Official result history cannot be removed');
                END
                SQL);
        }

        if (! $this->triggerExists($insertTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$insertTrigger}
                BEFORE INSERT ON season_event_results
                WHEN (NEW.status = 'published' AND NEW.published_at IS NULL)
                    OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Official result publication status and date are inconsistent');
                END
                SQL);
        }
    }

    /** @param array{update: string, delete: string, insert: string} $triggers */
    private function installMySqlOfficialResultTriggers(array $triggers): void
    {
        $updateTrigger = $triggers['update'];
        $deleteTrigger = $triggers['delete'];
        $insertTrigger = $triggers['insert'];

        if (! $this->triggerExists($updateTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON season_event_results
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (
                            (
                                (OLD.status = 'draft' AND NEW.status IN ('pending', 'published'))
                                OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published'))
                                OR (OLD.status = 'failed' AND NEW.status = 'pending')
                            )
                            AND OLD.id <=> NEW.id
                            AND OLD.season_event_id <=> NEW.season_event_id
                            AND OLD.created_by <=> NEW.created_by
                            AND OLD.supersedes_id <=> NEW.supersedes_id
                            AND OLD.version <=> NEW.version
                            AND OLD.correction_reason <=> NEW.correction_reason
                            AND OLD.created_at <=> NEW.created_at
                            AND (
                                (NEW.status = 'published' AND NEW.published_at IS NOT NULL)
                                OR (NEW.status <> 'published' AND NEW.published_at IS NULL)
                            )
                        )
                        OR (
                            OLD.status = 'draft'
                            AND NEW.status = 'draft'
                            AND NEW.published_at IS NULL
                            AND OLD.id <=> NEW.id
                            AND OLD.season_event_id <=> NEW.season_event_id
                            AND OLD.created_by <=> NEW.created_by
                            AND OLD.supersedes_id <=> NEW.supersedes_id
                            AND OLD.version <=> NEW.version
                            AND OLD.created_at <=> NEW.created_at
                        )
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result history is immutable';
                    END IF;
                END
                SQL);
        }

        if (! $this->triggerExists($deleteTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON season_event_results
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result history cannot be removed'
                SQL);
        }

        if (! $this->triggerExists($insertTrigger)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$insertTrigger}
                BEFORE INSERT ON season_event_results
                FOR EACH ROW
                BEGIN
                    IF (NEW.status = 'published' AND NEW.published_at IS NULL)
                        OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result publication status and date are inconsistent';
                    END IF;
                END
                SQL);
        }
    }

    private function triggerExists(string $triggerName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $result = DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'trigger' AND name = ?",
                [$triggerName],
            );

            return (int) $result->aggregate > 0;
        }

        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$triggerName],
        );

        return (int) $result->aggregate > 0;
    }
};
