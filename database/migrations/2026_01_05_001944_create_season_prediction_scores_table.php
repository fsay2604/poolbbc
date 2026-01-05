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
        Schema::create('season_prediction_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('season_prediction_id')->constrained('season_predictions')->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('points');
            $table->json('breakdown');
            $table->timestamp('calculated_at');

            $table->unique(['season_prediction_id']);
            $table->unique(['season_id', 'user_id']);
            $table->index(['user_id', 'season_id']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_prediction_scores');
    }
};
