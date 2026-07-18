<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'canonical_flow_authority_valid_insert',
        'canonical_flow_authority_immutable_update',
        'canonical_flow_authority_immutable_delete',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installTriggers([
                "CREATE TRIGGER canonical_flow_authority_valid_insert BEFORE INSERT ON canonical_flow_authorities WHEN NEW.marker <> 'canonical' OR NEW.activated_at IS NULL BEGIN SELECT RAISE(ABORT, 'Invalid canonical flow authority marker'); END",
                "CREATE TRIGGER canonical_flow_authority_immutable_update BEFORE UPDATE ON canonical_flow_authorities BEGIN SELECT RAISE(ABORT, 'Canonical flow authority is immutable'); END",
                "CREATE TRIGGER canonical_flow_authority_immutable_delete BEFORE DELETE ON canonical_flow_authorities BEGIN SELECT RAISE(ABORT, 'Canonical flow authority cannot be removed'); END",
            ]);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installTriggers([
                "CREATE TRIGGER canonical_flow_authority_valid_insert BEFORE INSERT ON canonical_flow_authorities FOR EACH ROW BEGIN IF NEW.marker <> 'canonical' OR NEW.activated_at IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid canonical flow authority marker'; END IF; END",
                "CREATE TRIGGER canonical_flow_authority_immutable_update BEFORE UPDATE ON canonical_flow_authorities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Canonical flow authority is immutable'",
                "CREATE TRIGGER canonical_flow_authority_immutable_delete BEFORE DELETE ON canonical_flow_authorities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Canonical flow authority cannot be removed'",
            ]);

            return;
        }

        throw new RuntimeException("Canonical flow authority immutability is not implemented for the [{$driver}] database driver.");
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

    /** @param list<string> $statements */
    private function installTriggers(array $statements): void
    {
        foreach ($statements as $index => $statement) {
            DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGERS[$index]);
            DB::unprepared($statement);
        }
    }
};
