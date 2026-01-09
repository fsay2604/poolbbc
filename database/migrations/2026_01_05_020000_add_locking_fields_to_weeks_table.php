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
            $table->boolean('is_locked')->default(true)->after('prediction_deadline_at');
            $table->dateTime('auto_lock_at')->nullable()->after('is_locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weeks', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'auto_lock_at']);
        });
    }
};
