<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->down();

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pool_events_definition_immutable
                BEFORE UPDATE OF mode, visibility, prediction_min_selections, prediction_max_selections, scoring_config ON pool_events
                WHEN (
                    OLD.mode IS NOT NEW.mode
                    OR OLD.visibility IS NOT NEW.visibility
                    OR OLD.prediction_min_selections IS NOT NEW.prediction_min_selections
                    OR OLD.prediction_max_selections IS NOT NEW.prediction_max_selections
                    OR OLD.scoring_config IS NOT NEW.scoring_config
                ) AND (
                    EXISTS (SELECT 1 FROM pool_event_predictions WHERE pool_event_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM point_entries WHERE pool_event_id = OLD.id)
                    OR EXISTS (
                        SELECT 1 FROM season_events
                        WHERE id = OLD.season_event_id
                        AND status IN ('locked', 'result_entered', 'published', 'cancelled')
                    )
                    OR EXISTS (
                        SELECT 1 FROM events
                        WHERE id = OLD.local_event_id
                        AND status <> 'draft'
                    )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Pool event scoring rules are immutable after opening or the first response');
                END
                SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pool_events_definition_immutable
                BEFORE UPDATE ON pool_events
                FOR EACH ROW
                BEGIN
                    IF (
                        NOT (OLD.mode <=> NEW.mode)
                        OR NOT (OLD.visibility <=> NEW.visibility)
                        OR NOT (OLD.prediction_min_selections <=> NEW.prediction_min_selections)
                        OR NOT (OLD.prediction_max_selections <=> NEW.prediction_max_selections)
                        OR NOT (OLD.scoring_config <=> NEW.scoring_config)
                    ) AND (
                        EXISTS (SELECT 1 FROM pool_event_predictions WHERE pool_event_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM point_entries WHERE pool_event_id = OLD.id)
                        OR EXISTS (
                            SELECT 1 FROM season_events
                            WHERE id = OLD.season_event_id
                            AND status IN ('locked', 'result_entered', 'published', 'cancelled')
                        )
                        OR EXISTS (
                            SELECT 1 FROM events
                            WHERE id = OLD.local_event_id
                            AND status <> 'draft'
                        )
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pool event scoring rules are immutable after opening or the first response';
                    END IF;
                END
                SQL);

            return;
        }

        throw new \RuntimeException("Pool event definition immutability is not implemented for database driver [{$driver}].");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS pool_events_definition_immutable');
    }
};
