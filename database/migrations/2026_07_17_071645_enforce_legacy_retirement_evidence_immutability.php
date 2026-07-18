<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'legacy_shadow_observations_immutable_update',
        'legacy_shadow_observations_immutable_delete',
        'legacy_backup_attestations_immutable_update',
        'legacy_backup_attestations_immutable_delete',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installTriggers([
                "CREATE TRIGGER legacy_shadow_observations_immutable_update BEFORE UPDATE ON legacy_shadow_observations BEGIN SELECT RAISE(ABORT, 'Legacy shadow observations are append-only'); END",
                "CREATE TRIGGER legacy_shadow_observations_immutable_delete BEFORE DELETE ON legacy_shadow_observations BEGIN SELECT RAISE(ABORT, 'Legacy shadow observations cannot be removed'); END",
                "CREATE TRIGGER legacy_backup_attestations_immutable_update BEFORE UPDATE ON legacy_backup_restore_attestations BEGIN SELECT RAISE(ABORT, 'Legacy backup restore attestations are append-only'); END",
                "CREATE TRIGGER legacy_backup_attestations_immutable_delete BEFORE DELETE ON legacy_backup_restore_attestations BEGIN SELECT RAISE(ABORT, 'Legacy backup restore attestations cannot be removed'); END",
            ]);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installTriggers([
                "CREATE TRIGGER legacy_shadow_observations_immutable_update BEFORE UPDATE ON legacy_shadow_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy shadow observations are append-only'",
                "CREATE TRIGGER legacy_shadow_observations_immutable_delete BEFORE DELETE ON legacy_shadow_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy shadow observations cannot be removed'",
                "CREATE TRIGGER legacy_backup_attestations_immutable_update BEFORE UPDATE ON legacy_backup_restore_attestations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy backup restore attestations are append-only'",
                "CREATE TRIGGER legacy_backup_attestations_immutable_delete BEFORE DELETE ON legacy_backup_restore_attestations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy backup restore attestations cannot be removed'",
            ]);

            return;
        }

        throw new RuntimeException("Legacy retirement evidence immutability is not implemented for the [{$driver}] database driver.");
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
