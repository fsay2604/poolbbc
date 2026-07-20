<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'season_events_safe_delete',
        'season_rounds_safe_delete',
        'seasons_safe_official_structure_delete',
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

        throw new RuntimeException("Safe official structure deletion is not implemented for the [{$driver}] database driver.");
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function installSqliteTriggers(): void
    {
        $unsafeEvent = $this->unsafeEventPredicate('OLD');
        $unsafeRound = $this->unsafeRoundPredicate('OLD');
        $unsafeSeasonRound = $this->unsafeRoundPredicate('season_rounds');

        DB::unprepared(<<<SQL
            CREATE TRIGGER season_events_safe_delete
            BEFORE DELETE ON season_events
            WHEN {$unsafeEvent}
            BEGIN
                SELECT RAISE(ABORT, 'Only unopened official events without responses, results, or points can be deleted');
            END
            SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER season_rounds_safe_delete
            BEFORE DELETE ON season_rounds
            WHEN {$unsafeRound}
            BEGIN
                SELECT RAISE(ABORT, 'Only draft official rounds containing safe draft events can be deleted');
            END
            SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER seasons_safe_official_structure_delete
            BEFORE DELETE ON seasons
            WHEN EXISTS (
                SELECT 1
                FROM season_rounds
                WHERE season_rounds.season_id = OLD.id
                  AND {$unsafeSeasonRound}
            )
            BEGIN
                SELECT RAISE(ABORT, 'A season containing protected official structure cannot be deleted');
            END
            SQL);
    }

    private function installMySqlTriggers(): void
    {
        $unsafeEvent = $this->unsafeEventPredicate('OLD');
        $unsafeRound = $this->unsafeRoundPredicate('OLD');
        $unsafeSeasonRound = $this->unsafeRoundPredicate('season_rounds');

        DB::unprepared(<<<SQL
            CREATE TRIGGER season_events_safe_delete
            BEFORE DELETE ON season_events
            FOR EACH ROW
            BEGIN
                IF {$unsafeEvent} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only unopened official events without responses, results, or points can be deleted';
                END IF;
            END
            SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER season_rounds_safe_delete
            BEFORE DELETE ON season_rounds
            FOR EACH ROW
            BEGIN
                IF {$unsafeRound} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only draft official rounds containing safe draft events can be deleted';
                END IF;
            END
            SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER seasons_safe_official_structure_delete
            BEFORE DELETE ON seasons
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM season_rounds
                    WHERE season_rounds.season_id = OLD.id
                      AND {$unsafeSeasonRound}
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A season containing protected official structure cannot be deleted';
                END IF;
            END
            SQL);
    }

    private function unsafeRoundPredicate(string $roundReference): string
    {
        $unsafeEvent = $this->unsafeEventPredicate('season_events');

        return "({$roundReference}.status <> 'draft' OR EXISTS (SELECT 1 FROM season_events WHERE season_events.season_round_id = {$roundReference}.id AND {$unsafeEvent}))";
    }

    private function unsafeEventPredicate(string $eventReference): string
    {
        return "({$eventReference}.status <> 'draft'
            OR {$eventReference}.options_locked_at IS NOT NULL
            OR EXISTS (
                SELECT 1
                FROM pool_event_predictions
                INNER JOIN pool_events ON pool_events.id = pool_event_predictions.pool_event_id
                WHERE pool_events.season_event_id = {$eventReference}.id
            )
            OR EXISTS (
                SELECT 1
                FROM season_event_results
                WHERE season_event_results.season_event_id = {$eventReference}.id
            )
            OR EXISTS (
                SELECT 1
                FROM point_entries
                INNER JOIN pool_events ON pool_events.id = point_entries.pool_event_id
                WHERE pool_events.season_event_id = {$eventReference}.id
            ))";
    }
};
