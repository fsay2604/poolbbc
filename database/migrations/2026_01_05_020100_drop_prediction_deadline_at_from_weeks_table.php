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
        if (Schema::hasColumn('weeks', 'prediction_deadline_at')) {
            Schema::table('weeks', function (Blueprint $table) {
                $table->dropColumn('prediction_deadline_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('weeks', 'prediction_deadline_at')) {
            Schema::table('weeks', function (Blueprint $table) {
                $table->dateTime('prediction_deadline_at')->after('name');
            });
        }
    }
};
