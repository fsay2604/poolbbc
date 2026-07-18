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
        Schema::create('pool_event_prediction_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_event_prediction_id');
            $table->foreignId('season_event_option_id');
            $table->timestamps();

            $table->foreign('pool_event_prediction_id', 'pool_pred_selection_prediction_fk')
                ->references('id')
                ->on('pool_event_predictions')
                ->cascadeOnDelete();
            $table->foreign('season_event_option_id', 'pool_pred_selection_option_fk')
                ->references('id')
                ->on('season_event_options')
                ->cascadeOnDelete();
            $table->unique(['pool_event_prediction_id', 'season_event_option_id'], 'pool_prediction_option_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('pool_event_prediction_selections')->exists()) {
            throw new RuntimeException('Cannot drop canonical prediction selections while submitted selections exist.');
        }

        Schema::dropIfExists('pool_event_prediction_selections');
    }
};
