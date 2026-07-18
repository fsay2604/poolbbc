<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'event_types_scope_key_consistent_insert',
        'event_types_scope_key_consistent_update',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installTriggers([
                'event_types_scope_key_consistent_insert' => "CREATE TRIGGER event_types_scope_key_consistent_insert BEFORE INSERT ON event_types WHEN NEW.scope_key IS NOT (CASE WHEN NEW.pool_id IS NULL THEN 'global' ELSE 'pool:' || NEW.pool_id END) BEGIN SELECT RAISE(ABORT, 'Event type scope key does not match its pool'); END",
                'event_types_scope_key_consistent_update' => "CREATE TRIGGER event_types_scope_key_consistent_update BEFORE UPDATE ON event_types WHEN NEW.scope_key IS NOT (CASE WHEN NEW.pool_id IS NULL THEN 'global' ELSE 'pool:' || NEW.pool_id END) BEGIN SELECT RAISE(ABORT, 'Event type scope key does not match its pool'); END",
            ]);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installTriggers([
                'event_types_scope_key_consistent_insert' => "CREATE TRIGGER event_types_scope_key_consistent_insert BEFORE INSERT ON event_types FOR EACH ROW BEGIN IF NOT (NEW.scope_key <=> IF(NEW.pool_id IS NULL, 'global', CONCAT('pool:', NEW.pool_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event type scope key does not match its pool'; END IF; END",
                'event_types_scope_key_consistent_update' => "CREATE TRIGGER event_types_scope_key_consistent_update BEFORE UPDATE ON event_types FOR EACH ROW BEGIN IF NOT (NEW.scope_key <=> IF(NEW.pool_id IS NULL, 'global', CONCAT('pool:', NEW.pool_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event type scope key does not match its pool'; END IF; END",
            ]);

            return;
        }

        throw new RuntimeException("Event type scope consistency is not implemented for the [{$driver}] database driver.");
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

    /** @param array<string, string> $statements */
    private function installTriggers(array $statements): void
    {
        foreach ($statements as $trigger => $statement) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            DB::unprepared($statement);
        }
    }
};
