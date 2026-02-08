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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->verifyPhaseMigrationOrFail();

        Schema::table('weeks', function (Blueprint $table) {
            foreach (['weeks_boss_count_index', 'weeks_nominee_count_index', 'weeks_evicted_count_index'] as $indexName) {
                if ($this->indexExists('weeks', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }

            if (Schema::hasColumn('weeks', 'boss_count')) {
                $table->dropColumn('boss_count');
            }

            if (Schema::hasColumn('weeks', 'nominee_count')) {
                $table->dropColumn('nominee_count');
            }

            if (Schema::hasColumn('weeks', 'evicted_count')) {
                $table->dropColumn('evicted_count');
            }
        });

        Schema::table('predictions', function (Blueprint $table) {
            foreach ([
                'hoh_houseguest_id',
                'nominee_1_houseguest_id',
                'nominee_2_houseguest_id',
                'veto_winner_houseguest_id',
                'saved_houseguest_id',
                'replacement_nominee_houseguest_id',
                'evicted_houseguest_id',
            ] as $column) {
                if (Schema::hasColumn('predictions', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            if (Schema::hasColumn('predictions', 'veto_used')) {
                $table->dropColumn('veto_used');
            }

            foreach (['boss_houseguest_ids', 'nominee_houseguest_ids', 'evicted_houseguest_ids'] as $jsonColumn) {
                if (Schema::hasColumn('predictions', $jsonColumn)) {
                    $table->dropColumn($jsonColumn);
                }
            }
        });

        Schema::table('week_outcomes', function (Blueprint $table) {
            foreach ([
                'hoh_houseguest_id',
                'nominee_1_houseguest_id',
                'nominee_2_houseguest_id',
                'veto_winner_houseguest_id',
                'saved_houseguest_id',
                'replacement_nominee_houseguest_id',
                'evicted_houseguest_id',
            ] as $column) {
                if (Schema::hasColumn('week_outcomes', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            if (Schema::hasColumn('week_outcomes', 'veto_used')) {
                $table->dropColumn('veto_used');
            }

            foreach (['boss_houseguest_ids', 'nominee_houseguest_ids', 'evicted_houseguest_ids'] as $jsonColumn) {
                if (Schema::hasColumn('week_outcomes', $jsonColumn)) {
                    $table->dropColumn($jsonColumn);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weeks', function (Blueprint $table) {
            if (! Schema::hasColumn('weeks', 'nominee_count')) {
                $table->unsignedTinyInteger('nominee_count')->default(2)->index();
            }

            if (! Schema::hasColumn('weeks', 'evicted_count')) {
                $table->unsignedTinyInteger('evicted_count')->default(1)->index();
            }

            if (! Schema::hasColumn('weeks', 'boss_count')) {
                $table->unsignedTinyInteger('boss_count')->default(1)->index();
            }
        });

        Schema::table('predictions', function (Blueprint $table) {
            if (! Schema::hasColumn('predictions', 'hoh_houseguest_id')) {
                $table->foreignId('hoh_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'boss_houseguest_ids')) {
                $table->json('boss_houseguest_ids')->nullable();
            }

            if (! Schema::hasColumn('predictions', 'nominee_1_houseguest_id')) {
                $table->foreignId('nominee_1_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'nominee_2_houseguest_id')) {
                $table->foreignId('nominee_2_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'nominee_houseguest_ids')) {
                $table->json('nominee_houseguest_ids')->nullable();
            }

            if (! Schema::hasColumn('predictions', 'veto_winner_houseguest_id')) {
                $table->foreignId('veto_winner_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'veto_used')) {
                $table->boolean('veto_used')->nullable();
            }

            if (! Schema::hasColumn('predictions', 'saved_houseguest_id')) {
                $table->foreignId('saved_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'replacement_nominee_houseguest_id')) {
                $table->foreignId('replacement_nominee_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'evicted_houseguest_id')) {
                $table->foreignId('evicted_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('predictions', 'evicted_houseguest_ids')) {
                $table->json('evicted_houseguest_ids')->nullable();
            }
        });

        Schema::table('week_outcomes', function (Blueprint $table) {
            if (! Schema::hasColumn('week_outcomes', 'hoh_houseguest_id')) {
                $table->foreignId('hoh_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'boss_houseguest_ids')) {
                $table->json('boss_houseguest_ids')->nullable();
            }

            if (! Schema::hasColumn('week_outcomes', 'nominee_1_houseguest_id')) {
                $table->foreignId('nominee_1_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'nominee_2_houseguest_id')) {
                $table->foreignId('nominee_2_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'nominee_houseguest_ids')) {
                $table->json('nominee_houseguest_ids')->nullable();
            }

            if (! Schema::hasColumn('week_outcomes', 'veto_winner_houseguest_id')) {
                $table->foreignId('veto_winner_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'veto_used')) {
                $table->boolean('veto_used')->nullable();
            }

            if (! Schema::hasColumn('week_outcomes', 'saved_houseguest_id')) {
                $table->foreignId('saved_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'replacement_nominee_houseguest_id')) {
                $table->foreignId('replacement_nominee_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'evicted_houseguest_id')) {
                $table->foreignId('evicted_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            }

            if (! Schema::hasColumn('week_outcomes', 'evicted_houseguest_ids')) {
                $table->json('evicted_houseguest_ids')->nullable();
            }
        });
    }

    private function verifyPhaseMigrationOrFail(): void
    {
        $weeksWithoutPhases = DB::table('weeks')
            ->leftJoin('week_phases', 'week_phases.week_id', '=', 'weeks.id')
            ->whereNull('week_phases.id')
            ->count();

        if ($weeksWithoutPhases > 0) {
            throw new RuntimeException("Phase migration verification failed: {$weeksWithoutPhases} weeks have no phases.");
        }

        $predictionsWithoutPayload = DB::table('predictions')
            ->whereNull('phase_picks')
            ->count();

        if ($predictionsWithoutPayload > 0) {
            throw new RuntimeException("Phase migration verification failed: {$predictionsWithoutPayload} predictions have no phase payload.");
        }

        $outcomesWithoutPayload = DB::table('week_outcomes')
            ->whereNull('phase_results')
            ->count();

        if ($outcomesWithoutPayload > 0) {
            throw new RuntimeException("Phase migration verification failed: {$outcomesWithoutPayload} outcomes have no phase payload.");
        }

        $this->verifyPayloadPhaseReferences('predictions', 'phase_picks');
        $this->verifyPayloadPhaseReferences('week_outcomes', 'phase_results');
    }

    private function verifyPayloadPhaseReferences(string $table, string $column): void
    {
        $records = DB::table($table)
            ->select('id', 'week_id', $column)
            ->get();

        foreach ($records as $record) {
            $weekPhaseIds = DB::table('week_phases')
                ->where('week_id', $record->week_id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $payload = json_decode((string) $record->{$column}, true);
            if (! is_array($payload)) {
                throw new RuntimeException("Phase migration verification failed: {$table} #{$record->id} has invalid payload JSON.");
            }

            $payloadPhaseIds = [];
            foreach ($payload as $entry) {
                if (! is_array($entry) || ! is_numeric($entry['phase_id'] ?? null)) {
                    throw new RuntimeException("Phase migration verification failed: {$table} #{$record->id} has an invalid phase entry.");
                }

                $phaseId = (int) $entry['phase_id'];
                if (! in_array($phaseId, $weekPhaseIds, true)) {
                    throw new RuntimeException("Phase migration verification failed: {$table} #{$record->id} references unknown phase #{$phaseId}.");
                }

                if (! in_array($phaseId, $payloadPhaseIds, true)) {
                    $payloadPhaseIds[] = $phaseId;
                }
            }

            sort($weekPhaseIds);
            sort($payloadPhaseIds);

            if ($payloadPhaseIds !== $weekPhaseIds) {
                throw new RuntimeException("Phase migration verification failed: {$table} #{$record->id} is missing one or more phase entries.");
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        $database = DB::getDatabaseName();

        $count = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->count();

        return $count > 0;
    }
};
