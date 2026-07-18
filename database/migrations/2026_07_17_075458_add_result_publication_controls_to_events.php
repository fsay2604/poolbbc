<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('result_publication_mode')->default('immediate')->after('scoring_config');
        });

        Schema::table('season_events', function (Blueprint $table) {
            $table->string('result_publication_mode')->default('immediate')->after('result_max_selections');
        });

        $this->changeLocalResultPublicationTimestamp(nullable: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('event_results')->whereNull('published_at')->exists()) {
            throw new \RuntimeException('Publish or remove entered local results before rolling back result publication controls.');
        }

        $this->changeLocalResultPublicationTimestamp(nullable: false);

        Schema::table('season_events', function (Blueprint $table) {
            $table->dropColumn('result_publication_mode');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('result_publication_mode');
        });
    }

    private function changeLocalResultPublicationTimestamp(bool $nullable): void
    {
        $this->dropLocalResultTriggers();

        try {
            Schema::table('event_results', function (Blueprint $table) use ($nullable) {
                $table->timestamp('published_at')->nullable($nullable)->change();
            });
        } finally {
            if (Schema::hasTable('event_results')) {
                $this->installLocalResultTriggers();
            }
        }
    }

    private function dropLocalResultTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS event_results_immutable_update');
        DB::statement('DROP TRIGGER IF EXISTS event_results_immutable_delete');
        DB::statement('DROP TRIGGER IF EXISTS event_result_selections_immutable_insert');
        DB::statement('DROP TRIGGER IF EXISTS event_result_selections_immutable_update');
        DB::statement('DROP TRIGGER IF EXISTS event_result_selections_immutable_delete');
    }

    private function installLocalResultTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results WHEN NOT (OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id IS NEW.id AND OLD.event_id IS NEW.event_id AND OLD.created_by IS NEW.created_by AND OLD.supersedes_id IS NEW.supersedes_id AND OLD.version IS NEW.version AND OLD.correction_reason IS NEW.correction_reason AND OLD.created_at IS NEW.created_at) BEGIN SELECT RAISE(ABORT, 'Published event results are immutable'); END");
            DB::unprepared("CREATE TRIGGER event_results_immutable_delete BEFORE DELETE ON event_results BEGIN SELECT RAISE(ABORT, 'Event result history cannot be removed'); END");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_insert BEFORE INSERT ON event_result_selections WHEN COALESCE((SELECT status FROM event_results WHERE id = NEW.event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_update BEFORE UPDATE ON event_result_selections BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_delete BEFORE DELETE ON event_result_selections WHEN COALESCE((SELECT status FROM event_results WHERE id = OLD.event_result_id), 'missing') <> 'draft' BEGIN SELECT RAISE(ABORT, 'Event result selections are immutable'); END");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER event_results_immutable_update BEFORE UPDATE ON event_results FOR EACH ROW BEGIN IF NOT (OLD.status = 'draft' AND NEW.status = 'published' AND NEW.published_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.event_id <=> NEW.event_id AND OLD.created_by <=> NEW.created_by AND OLD.supersedes_id <=> NEW.supersedes_id AND OLD.version <=> NEW.version AND OLD.correction_reason <=> NEW.correction_reason AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published event results are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER event_results_immutable_delete BEFORE DELETE ON event_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result history cannot be removed'");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_insert BEFORE INSERT ON event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM event_results WHERE id = NEW.event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_update BEFORE UPDATE ON event_result_selections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'");
            DB::unprepared("CREATE TRIGGER event_result_selections_immutable_delete BEFORE DELETE ON event_result_selections FOR EACH ROW BEGIN IF COALESCE((SELECT status FROM event_results WHERE id = OLD.event_result_id), 'missing') <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event result selections are immutable'; END IF; END");

            return;
        }

        throw new \RuntimeException("Canonical result immutability is not implemented for database driver [{$driver}].");
    }
};
