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
        Schema::table('seasons', function (Blueprint $table) {
            $table->foreignId('winner_houseguest_id')->nullable()->after('ends_on')->constrained('houseguests')->nullOnDelete();
            $table->foreignId('first_evicted_houseguest_id')->nullable()->after('winner_houseguest_id')->constrained('houseguests')->nullOnDelete();
            $table->json('top_6_houseguest_ids')->nullable()->after('first_evicted_houseguest_id');

            $table->index(['winner_houseguest_id']);
            $table->index(['first_evicted_houseguest_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('top_6_houseguest_ids');
            $table->dropConstrainedForeignId('first_evicted_houseguest_id');
            $table->dropConstrainedForeignId('winner_houseguest_id');
        });
    }
};
