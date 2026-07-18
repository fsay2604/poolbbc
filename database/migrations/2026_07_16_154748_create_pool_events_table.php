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
        Schema::create('pool_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('local_event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('mode');
            $table->boolean('is_active')->default(true)->index();
            $table->string('visibility')->default('after_lock');
            $table->unsignedSmallInteger('prediction_min_selections')->default(1);
            $table->unsignedSmallInteger('prediction_max_selections')->default(1);
            $table->json('scoring_config');
            $table->timestamps();

            $table->unique(['pool_id', 'season_event_id']);
            $table->unique(['pool_id', 'local_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pool_events');
    }
};
