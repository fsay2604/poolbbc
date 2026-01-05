<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('weeks', function (Blueprint $table) {
            if (! Schema::hasColumn('weeks', 'boss_count')) {
                $table->unsignedTinyInteger('boss_count')->default(1)->after('evicted_count')->index();
            }
        });

        Schema::table('predictions', function (Blueprint $table) {
            if (! Schema::hasColumn('predictions', 'boss_houseguest_ids')) {
                $table->json('boss_houseguest_ids')->nullable()->after('hoh_houseguest_id');
            }
        });

        Schema::table('week_outcomes', function (Blueprint $table) {
            if (! Schema::hasColumn('week_outcomes', 'boss_houseguest_ids')) {
                $table->json('boss_houseguest_ids')->nullable()->after('hoh_houseguest_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('week_outcomes', function (Blueprint $table) {
            if (Schema::hasColumn('week_outcomes', 'boss_houseguest_ids')) {
                $table->dropColumn('boss_houseguest_ids');
            }
        });

        Schema::table('predictions', function (Blueprint $table) {
            if (Schema::hasColumn('predictions', 'boss_houseguest_ids')) {
                $table->dropColumn('boss_houseguest_ids');
            }
        });

        Schema::table('weeks', function (Blueprint $table) {
            if (Schema::hasColumn('weeks', 'boss_count')) {
                $table->dropIndex(['boss_count']);
                $table->dropColumn('boss_count');
            }
        });
    }
};
