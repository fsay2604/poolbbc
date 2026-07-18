<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'event_results_immutable_update',
        'season_event_results_immutable_update',
        'event_results_published_at_consistent_insert',
        'season_event_results_published_at_consistent_insert',
    ];

    public function up(): void
    {
        $this->installTriggers(allowDraftAmendments: true);
    }

    public function down(): void
    {
        $this->installTriggers(allowDraftAmendments: false);
    }

    private function installTriggers(bool $allowDraftAmendments): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteTriggers($allowDraftAmendments);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMySqlTriggers($allowDraftAmendments);

            return;
        }

        throw new RuntimeException("Result status consistency is not implemented for the [{$driver}] database driver.");
    }

    private function installSqliteTriggers(bool $allowDraftAmendments): void
    {
        $localDraftAmendment = $allowDraftAmendments
            ? " OR (OLD.status = 'draft' AND NEW.status = 'draft' AND NEW.published_at IS NULL AND OLD.id IS NEW.id AND OLD.event_id IS NEW.event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.created_at IS NEW.created_at)"
            : '';
        $officialDraftAmendment = $allowDraftAmendments
            ? " OR (OLD.status = 'draft' AND NEW.status = 'draft' AND NEW.published_at IS NULL AND OLD.id IS NEW.id AND OLD.season_event_id IS NEW.season_event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.legacy_key IS NEW.legacy_key AND OLD.created_at IS NEW.created_at)"
            : '';

        DB::unprepared("CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results WHEN NOT ((OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id IS NEW.id AND OLD.event_id IS NEW.event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.correction_reason IS NEW.correction_reason AND OLD.created_at IS NEW.created_at){$localDraftAmendment}) BEGIN SELECT RAISE(ABORT, 'Published event results are immutable'); END");
        DB::unprepared("CREATE TRIGGER season_event_results_immutable_update BEFORE UPDATE ON season_event_results WHEN NOT ((((OLD.status = 'draft' AND NEW.status IN ('pending', 'published')) OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published')) OR (OLD.status = 'failed' AND NEW.status = 'pending')) AND OLD.id IS NEW.id AND OLD.season_event_id IS NEW.season_event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.correction_reason IS NEW.correction_reason AND OLD.legacy_key IS NEW.legacy_key AND OLD.created_at IS NEW.created_at AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NULL))){$officialDraftAmendment}) BEGIN SELECT RAISE(ABORT, 'Official result history is immutable'); END");

        if ($allowDraftAmendments) {
            DB::unprepared("CREATE TRIGGER event_results_published_at_consistent_insert BEFORE INSERT ON event_results WHEN (NEW.status = 'published' AND NEW.published_at IS NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Event result publication status and date are inconsistent'); END");
            DB::unprepared("CREATE TRIGGER season_event_results_published_at_consistent_insert BEFORE INSERT ON season_event_results WHEN (NEW.status = 'published' AND NEW.published_at IS NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Official result publication status and date are inconsistent'); END");
        }
    }

    private function installMySqlTriggers(bool $allowDraftAmendments): void
    {
        $localDraftAmendment = $allowDraftAmendments
            ? " OR (OLD.status = 'draft' AND NEW.status = 'draft' AND NEW.published_at IS NULL AND OLD.id <=> NEW.id AND OLD.event_id <=> NEW.event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.created_at <=> NEW.created_at)"
            : '';
        $officialDraftAmendment = $allowDraftAmendments
            ? " OR (OLD.status = 'draft' AND NEW.status = 'draft' AND NEW.published_at IS NULL AND OLD.id <=> NEW.id AND OLD.season_event_id <=> NEW.season_event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.legacy_key <=> NEW.legacy_key AND OLD.created_at <=> NEW.created_at)"
            : '';

        DB::unprepared("CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results FOR EACH ROW BEGIN IF NOT ((OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.event_id <=> NEW.event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.correction_reason <=> NEW.correction_reason AND OLD.created_at <=> NEW.created_at){$localDraftAmendment}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published event results are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER season_event_results_immutable_update BEFORE UPDATE ON season_event_results FOR EACH ROW BEGIN IF NOT ((((OLD.status = 'draft' AND NEW.status IN ('pending', 'published')) OR (OLD.status = 'pending' AND NEW.status IN ('failed', 'published')) OR (OLD.status = 'failed' AND NEW.status = 'pending')) AND OLD.id <=> NEW.id AND OLD.season_event_id <=> NEW.season_event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.correction_reason <=> NEW.correction_reason AND OLD.legacy_key <=> NEW.legacy_key AND OLD.created_at <=> NEW.created_at AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NULL))){$officialDraftAmendment}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result history is immutable'; END IF; END");

        if ($allowDraftAmendments) {
            DB::unprepared("CREATE TRIGGER event_results_published_at_consistent_insert BEFORE INSERT ON event_results FOR EACH ROW BEGIN IF (NEW.status = 'published' AND NEW.published_at IS NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result publication status and date are inconsistent'; END IF; END");
            DB::unprepared("CREATE TRIGGER season_event_results_published_at_consistent_insert BEFORE INSERT ON season_event_results FOR EACH ROW BEGIN IF (NEW.status = 'published' AND NEW.published_at IS NULL) OR (NEW.status <> 'published' AND NEW.published_at IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official result publication status and date are inconsistent'; END IF; END");
        }
    }
};
