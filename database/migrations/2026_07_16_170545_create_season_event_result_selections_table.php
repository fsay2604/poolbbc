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
        Schema::create('season_event_result_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_event_result_id');
            $table->foreignId('season_event_option_id');
            $table->timestamps();

            $table->foreign('season_event_result_id', 'season_result_selection_result_fk')
                ->references('id')
                ->on('season_event_results')
                ->cascadeOnDelete();
            $table->foreign('season_event_option_id', 'season_result_selection_option_fk')
                ->references('id')
                ->on('season_event_options')
                ->cascadeOnDelete();
            $table->unique(['season_event_result_id', 'season_event_option_id'], 'season_result_option_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('season_event_result_selections')->exists()) {
            throw new RuntimeException('Cannot drop canonical result selections while published selections exist.');
        }

        Schema::dropIfExists('season_event_result_selections');
    }
};
