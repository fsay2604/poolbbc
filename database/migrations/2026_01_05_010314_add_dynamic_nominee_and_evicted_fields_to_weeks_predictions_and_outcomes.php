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
            $table->unsignedTinyInteger('nominee_count')->default(2)->after('number');
            $table->unsignedTinyInteger('evicted_count')->default(1)->after('nominee_count');

            $table->index(['nominee_count']);
            $table->index(['evicted_count']);
        });

        Schema::table('predictions', function (Blueprint $table) {
            $table->json('nominee_houseguest_ids')->nullable()->after('nominee_2_houseguest_id');
            $table->json('evicted_houseguest_ids')->nullable()->after('evicted_houseguest_id');
        });

        Schema::table('week_outcomes', function (Blueprint $table) {
            $table->json('nominee_houseguest_ids')->nullable()->after('nominee_2_houseguest_id');
            $table->json('evicted_houseguest_ids')->nullable()->after('evicted_houseguest_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('week_outcomes', function (Blueprint $table) {
            $table->dropColumn('evicted_houseguest_ids');
            $table->dropColumn('nominee_houseguest_ids');
        });

        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn('evicted_houseguest_ids');
            $table->dropColumn('nominee_houseguest_ids');
        });

        Schema::table('weeks', function (Blueprint $table) {
            $table->dropIndex(['nominee_count']);
            $table->dropIndex(['evicted_count']);
            $table->dropColumn('evicted_count');
            $table->dropColumn('nominee_count');
        });
    }
};
