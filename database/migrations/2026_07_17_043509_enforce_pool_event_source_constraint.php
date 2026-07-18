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
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER pool_events_one_source_insert BEFORE INSERT ON pool_events WHEN ((NEW.season_event_id IS NULL) = (NEW.local_event_id IS NULL)) BEGIN SELECT RAISE(ABORT, 'A pool event must reference exactly one source'); END");
            DB::statement("CREATE TRIGGER pool_events_one_source_update BEFORE UPDATE OF season_event_id, local_event_id ON pool_events WHEN ((NEW.season_event_id IS NULL) = (NEW.local_event_id IS NULL)) BEGIN SELECT RAISE(ABORT, 'A pool event must reference exactly one source'); END");

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER pool_events_one_source_insert BEFORE INSERT ON pool_events FOR EACH ROW BEGIN IF ((NEW.season_event_id IS NULL) = (NEW.local_event_id IS NULL)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pool event must reference exactly one source'; END IF; END");
            DB::unprepared("CREATE TRIGGER pool_events_one_source_update BEFORE UPDATE ON pool_events FOR EACH ROW BEGIN IF ((NEW.season_event_id IS NULL) = (NEW.local_event_id IS NULL)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pool event must reference exactly one source'; END IF; END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS pool_events_one_source_insert');
        DB::statement('DROP TRIGGER IF EXISTS pool_events_one_source_update');
    }
};
