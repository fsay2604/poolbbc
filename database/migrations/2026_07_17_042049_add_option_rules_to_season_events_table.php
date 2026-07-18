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
        Schema::table('season_events', function (Blueprint $table) {
            $table->boolean('include_inactive_houseguests')->default(false)->after('answer_source');
            $table->boolean('allow_none')->default(false)->after('include_inactive_houseguests');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('season_events', function (Blueprint $table) {
            $table->dropColumn(['include_inactive_houseguests', 'allow_none']);
        });
    }
};
