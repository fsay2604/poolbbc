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
        Schema::create('event_prediction_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_prediction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_prediction_id', 'event_option_id'], 'event_prediction_option_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_prediction_selections');
    }
};
