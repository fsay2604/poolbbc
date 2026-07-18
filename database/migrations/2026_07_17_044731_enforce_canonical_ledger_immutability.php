<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'event_results_immutable_update',
        'event_results_immutable_delete',
        'season_event_results_immutable_update',
        'season_event_results_immutable_delete',
        'point_entries_append_only_update',
        'point_entries_append_only_delete',
        'event_result_selections_immutable_insert',
        'event_result_selections_immutable_update',
        'event_result_selections_immutable_delete',
        'season_result_selections_immutable_insert',
        'season_result_selections_immutable_update',
        'season_result_selections_immutable_delete',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMySqlTriggers();

            return;
        }

        throw new RuntimeException("Canonical ledger immutability is not implemented for the [{$driver}] database driver.");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        $statements = [
            "CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results WHEN NOT (OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id IS NEW.id AND OLD.event_id IS NEW.event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.correction_reason IS NEW.correction_reason AND OLD.created_at IS NEW.created_at) BEGIN SELECT RAISE(ABORT, 'Published event results are immutable'); END",
            "CREATE TRIGGER event_results_immutable_delete BEFORE DELETE ON event_results BEGIN SELECT RAISE(ABORT, 'Event result history cannot be removed'); END",
            "CREATE TRIGGER season_event_results_immutable_update BEFORE UPDATE ON season_event_results WHEN NOT (((OLD.status = 'draft' AND NEW.status IN ('pending', 'published')) OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published')) OR (OLD.status = 'failed' AND NEW.status = 'pending')) AND OLD.id IS NEW.id AND OLD.season_event_id IS NEW.season_event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.correction_reason IS NEW.correction_reason AND OLD.legacy_key IS NEW.legacy_key AND OLD.created_at IS NEW.created_at AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NULL))) BEGIN SELECT RAISE(ABORT, 'Official result history is immutable'); END",
            "CREATE TRIGGER season_event_results_immutable_delete BEFORE DELETE ON season_event_results BEGIN SELECT RAISE(ABORT, 'Official result history cannot be removed'); END",
            "CREATE TRIGGER point_entries_append_only_update BEFORE UPDATE ON point_entries BEGIN SELECT RAISE(ABORT, 'Point entries are append-only'); END",
            "CREATE TRIGGER point_entries_append_only_delete BEFORE DELETE ON point_entries BEGIN SELECT RAISE(ABORT, 'Point entries are append-only'); END",
            "CREATE TRIGGER event_result_selections_immutable_insert BEFORE INSERT ON event_result_selections WHEN COALESCE((SELECT status FROM event_results WHERE id = NEW.event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END",
            "CREATE TRIGGER event_result_selections_immutable_update BEFORE UPDATE ON event_result_selections BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END",
            "CREATE TRIGGER event_result_selections_immutable_delete BEFORE DELETE ON event_result_selections WHEN COALESCE((SELECT status FROM event_results WHERE id = OLD.event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END",
            "CREATE TRIGGER season_result_selections_immutable_insert BEFORE INSERT ON season_event_result_selections WHEN COALESCE((SELECT status FROM season_event_results WHERE id = NEW.season_event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Official result selections are immutable'); END",
            "CREATE TRIGGER season_result_selections_immutable_update BEFORE UPDATE ON season_event_result_selections BEGIN SELECT RAISE(ABORT, 'Official result selections are immutable'); END",
            "CREATE TRIGGER season_result_selections_immutable_delete BEFORE DELETE ON season_event_result_selections WHEN COALESCE((SELECT status FROM season_event_results WHERE id = OLD.season_event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Official result selections are immutable'); END",
        ];

        $this->installTriggers($statements);
    }

    private function createMySqlTriggers(): void
    {
        $statements = [
            "CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results FOR EACH ROW BEGIN IF NOT (OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.event_id <=> NEW.event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.correction_reason <=> NEW.correction_reason AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published event results are immutable'; END IF; END",
            "CREATE TRIGGER event_results_immutable_delete BEFORE DELETE ON event_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result history cannot be removed'",
            "CREATE TRIGGER season_event_results_immutable_update BEFORE UPDATE ON season_event_results FOR EACH ROW BEGIN IF NOT (((OLD.status = 'draft' AND NEW.status IN ('pending', 'published')) OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published')) OR (OLD.status = 'failed' AND NEW.status = 'pending')) AND OLD.id <=> NEW.id AND OLD.season_event_id <=> NEW.season_event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.correction_reason <=> NEW.correction_reason AND OLD.legacy_key <=> NEW.legacy_key AND OLD.created_at <=> NEW.created_at AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result history is immutable'; END IF; END",
            "CREATE TRIGGER season_event_results_immutable_delete BEFORE DELETE ON season_event_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result history cannot be removed'",
            "CREATE TRIGGER point_entries_append_only_update BEFORE UPDATE ON point_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Point entries are append-only'",
            "CREATE TRIGGER point_entries_append_only_delete BEFORE DELETE ON point_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Point entries are append-only'",
            "CREATE TRIGGER event_result_selections_immutable_insert BEFORE INSERT ON event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM event_results WHERE id = NEW.event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'; END IF; END",
            "CREATE TRIGGER event_result_selections_immutable_update BEFORE UPDATE ON event_result_selections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'",
            "CREATE TRIGGER event_result_selections_immutable_delete BEFORE DELETE ON event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM event_results WHERE id = OLD.event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'; END IF; END",
            "CREATE TRIGGER season_result_selections_immutable_insert BEFORE INSERT ON season_event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM season_event_results WHERE id = NEW.season_event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result selections are immutable'; END IF; END",
            "CREATE TRIGGER season_result_selections_immutable_update BEFORE UPDATE ON season_event_result_selections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result selections are immutable'",
            "CREATE TRIGGER season_result_selections_immutable_delete BEFORE DELETE ON season_event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM season_event_results WHERE id = OLD.season_event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result selections are immutable'; END IF; END",
        ];

        $this->installTriggers($statements);
    }

    /** @param list<string> $statements */
    private function installTriggers(array $statements): void
    {
        foreach ($statements as $index => $statement) {
            DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGERS[$index]);
            DB::unprepared($statement);
        }
    }
};
