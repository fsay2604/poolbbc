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
        Schema::table('point_entries', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->change();
            $table->foreignId('event_result_id')->nullable()->change();
            $table->foreignId('pool_event_id')->nullable()->after('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_event_result_id')->nullable()->after('event_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_event_prediction_id')->nullable()->after('event_prediction_id')->constrained()->nullOnDelete();
            $table->index(['pool_event_id', 'season_event_result_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('point_entries')->whereNull('event_id')->orWhereNull('event_result_id')->exists()) {
            throw new RuntimeException('Cannot roll back canonical point-entry columns while canonical or imported ledger entries exist. Preserve or migrate those entries first.');
        }

        Schema::table('point_entries', function (Blueprint $table) {
            $table->dropForeign(['pool_event_prediction_id']);
            $table->dropForeign(['season_event_result_id']);
            $table->dropForeign(['pool_event_id']);
            $table->dropIndex(['pool_event_id', 'season_event_result_id']);
            $table->dropColumn(['pool_event_id', 'season_event_result_id', 'pool_event_prediction_id']);
            $table->foreignId('event_id')->nullable(false)->change();
            $table->foreignId('event_result_id')->nullable(false)->change();
        });
    }
};
