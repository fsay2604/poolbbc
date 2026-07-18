<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'season_events_rules_immutable_update',
        'season_event_options_frozen_insert',
        'season_event_options_frozen_update',
        'season_event_options_frozen_delete',
        'pools_rules_immutable_update',
    ];

    public function up(): void
    {
        $this->down();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMySqlTriggers();

            return;
        }

        throw new RuntimeException("Canonical rule immutability is not implemented for the [{$driver}] database driver.");
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function installSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER season_events_rules_immutable_update
            BEFORE UPDATE OF season_round_id, event_type_id, name, question, answer_source, include_inactive_houseguests, allow_none, scoring_config, default_mode, position, opens_at, locks_at, prediction_min_selections, prediction_max_selections, result_min_selections, result_max_selections, result_publication_mode ON season_events
            WHEN (
                OLD.season_round_id IS NOT NEW.season_round_id
                OR OLD.event_type_id IS NOT NEW.event_type_id
                OR OLD.name IS NOT NEW.name
                OR OLD.question IS NOT NEW.question
                OR OLD.answer_source IS NOT NEW.answer_source
                OR OLD.include_inactive_houseguests IS NOT NEW.include_inactive_houseguests
                OR OLD.allow_none IS NOT NEW.allow_none
                OR OLD.scoring_config IS NOT NEW.scoring_config
                OR OLD.default_mode IS NOT NEW.default_mode
                OR OLD.position IS NOT NEW.position
                OR OLD.opens_at IS NOT NEW.opens_at
                OR OLD.locks_at IS NOT NEW.locks_at
                OR OLD.prediction_min_selections IS NOT NEW.prediction_min_selections
                OR OLD.prediction_max_selections IS NOT NEW.prediction_max_selections
                OR OLD.result_min_selections IS NOT NEW.result_min_selections
                OR OLD.result_max_selections IS NOT NEW.result_max_selections
                OR OLD.result_publication_mode IS NOT NEW.result_publication_mode
            ) AND (
                OLD.options_locked_at IS NOT NULL
                OR OLD.status <> 'draft'
                OR EXISTS (
                    SELECT 1 FROM pool_event_predictions
                    INNER JOIN pool_events ON pool_events.id = pool_event_predictions.pool_event_id
                    WHERE pool_events.season_event_id = OLD.id
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Official event rules are immutable after opening or the first response');
            END
            SQL);

        DB::unprepared($this->sqliteOptionTrigger('insert', 'NEW.season_event_id'));
        DB::unprepared($this->sqliteOptionTrigger('update', 'NEW.season_event_id, OLD.season_event_id'));
        DB::unprepared($this->sqliteOptionTrigger('delete', 'OLD.season_event_id'));

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pools_rules_immutable_update
            BEFORE UPDATE OF season_id, competition_mode, scoring_config, max_members, picks_per_member, draft_mode, exclusive_draft ON pools
            WHEN (
                OLD.season_id IS NOT NEW.season_id
                OR OLD.competition_mode IS NOT NEW.competition_mode
                OR OLD.scoring_config IS NOT NEW.scoring_config
                OR OLD.max_members IS NOT NEW.max_members
                OR OLD.picks_per_member IS NOT NEW.picks_per_member
                OR OLD.draft_mode IS NOT NEW.draft_mode
                OR OLD.exclusive_draft IS NOT NEW.exclusive_draft
            ) AND (
                OLD.status NOT IN ('configuration', 'registration')
                OR EXISTS (SELECT 1 FROM drafts WHERE drafts.pool_id = OLD.id AND drafts.status <> 'pending')
                OR EXISTS (SELECT 1 FROM pool_event_predictions WHERE pool_event_predictions.pool_member_id IN (SELECT id FROM pool_members WHERE pool_id = OLD.id))
                OR EXISTS (SELECT 1 FROM point_entries WHERE point_entries.pool_member_id IN (SELECT id FROM pool_members WHERE pool_id = OLD.id))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Pool rules are immutable after competition starts');
            END
            SQL);
    }

    private function installMySqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER season_events_rules_immutable_update
            BEFORE UPDATE ON season_events
            FOR EACH ROW
            BEGIN
                IF (
                    NOT (OLD.season_round_id <=> NEW.season_round_id)
                    OR NOT (OLD.event_type_id <=> NEW.event_type_id)
                    OR NOT (OLD.name <=> NEW.name)
                    OR NOT (OLD.question <=> NEW.question)
                    OR NOT (OLD.answer_source <=> NEW.answer_source)
                    OR NOT (OLD.include_inactive_houseguests <=> NEW.include_inactive_houseguests)
                    OR NOT (OLD.allow_none <=> NEW.allow_none)
                    OR NOT (OLD.scoring_config <=> NEW.scoring_config)
                    OR NOT (OLD.default_mode <=> NEW.default_mode)
                    OR NOT (OLD.position <=> NEW.position)
                    OR NOT (OLD.opens_at <=> NEW.opens_at)
                    OR NOT (OLD.locks_at <=> NEW.locks_at)
                    OR NOT (OLD.prediction_min_selections <=> NEW.prediction_min_selections)
                    OR NOT (OLD.prediction_max_selections <=> NEW.prediction_max_selections)
                    OR NOT (OLD.result_min_selections <=> NEW.result_min_selections)
                    OR NOT (OLD.result_max_selections <=> NEW.result_max_selections)
                    OR NOT (OLD.result_publication_mode <=> NEW.result_publication_mode)
                ) AND (
                    OLD.options_locked_at IS NOT NULL
                    OR OLD.status <> 'draft'
                    OR EXISTS (
                        SELECT 1 FROM pool_event_predictions
                        INNER JOIN pool_events ON pool_events.id = pool_event_predictions.pool_event_id
                        WHERE pool_events.season_event_id = OLD.id
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official event rules are immutable after opening or the first response';
                END IF;
            END
            SQL);

        DB::unprepared($this->mySqlOptionTrigger('insert', 'NEW.season_event_id'));
        DB::unprepared($this->mySqlOptionTrigger('update', 'NEW.season_event_id, OLD.season_event_id'));
        DB::unprepared($this->mySqlOptionTrigger('delete', 'OLD.season_event_id'));

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pools_rules_immutable_update
            BEFORE UPDATE ON pools
            FOR EACH ROW
            BEGIN
                IF (
                    NOT (OLD.season_id <=> NEW.season_id)
                    OR NOT (OLD.competition_mode <=> NEW.competition_mode)
                    OR NOT (OLD.scoring_config <=> NEW.scoring_config)
                    OR NOT (OLD.max_members <=> NEW.max_members)
                    OR NOT (OLD.picks_per_member <=> NEW.picks_per_member)
                    OR NOT (OLD.draft_mode <=> NEW.draft_mode)
                    OR NOT (OLD.exclusive_draft <=> NEW.exclusive_draft)
                ) AND (
                    OLD.status NOT IN ('configuration', 'registration')
                    OR EXISTS (SELECT 1 FROM drafts WHERE drafts.pool_id = OLD.id AND drafts.status <> 'pending')
                    OR EXISTS (SELECT 1 FROM pool_event_predictions WHERE pool_event_predictions.pool_member_id IN (SELECT id FROM pool_members WHERE pool_id = OLD.id))
                    OR EXISTS (SELECT 1 FROM point_entries WHERE point_entries.pool_member_id IN (SELECT id FROM pool_members WHERE pool_id = OLD.id))
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pool rules are immutable after competition starts';
                END IF;
            END
            SQL);
    }

    private function sqliteOptionTrigger(string $operation, string $eventIds): string
    {
        $trigger = 'season_event_options_frozen_'.$operation;

        return "CREATE TRIGGER {$trigger} BEFORE ".strtoupper($operation)." ON season_event_options WHEN EXISTS (SELECT 1 FROM season_events WHERE id IN ({$eventIds}) AND (options_locked_at IS NOT NULL OR status <> 'draft' OR EXISTS (SELECT 1 FROM pool_events INNER JOIN pool_event_predictions ON pool_event_predictions.pool_event_id = pool_events.id WHERE pool_events.season_event_id = season_events.id) OR EXISTS (SELECT 1 FROM season_event_results WHERE season_event_results.season_event_id = season_events.id))) BEGIN SELECT RAISE(ABORT, 'Official options are frozen after opening or the first response'); END";
    }

    private function mySqlOptionTrigger(string $operation, string $eventIds): string
    {
        $trigger = 'season_event_options_frozen_'.$operation;

        return "CREATE TRIGGER {$trigger} BEFORE ".strtoupper($operation)." ON season_event_options FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM season_events WHERE id IN ({$eventIds}) AND (options_locked_at IS NOT NULL OR status <> 'draft' OR EXISTS (SELECT 1 FROM pool_events INNER JOIN pool_event_predictions ON pool_event_predictions.pool_event_id = pool_events.id WHERE pool_events.season_event_id = season_events.id) OR EXISTS (SELECT 1 FROM season_event_results WHERE season_event_results.season_event_id = season_events.id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official options are frozen after opening or the first response'; END IF; END";
    }
};
