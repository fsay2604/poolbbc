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
        foreach (['pools', 'season_rounds', 'season_events', 'pool_event_predictions', 'season_event_results'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('legacy_key')->nullable()->unique();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $canonicalTables = ['season_rounds', 'season_events', 'pool_event_predictions', 'season_event_results'];
        if (collect($canonicalTables)->contains(fn (string $tableName): bool => DB::table($tableName)->exists())) {
            throw new RuntimeException('Cannot remove canonical import keys while canonical flow records exist. Preserve or migrate those records first.');
        }

        foreach (['season_event_results', 'pool_event_predictions', 'season_events', 'season_rounds', 'pools'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['legacy_key']);
                $table->dropColumn('legacy_key');
            });
        }
    }
};
