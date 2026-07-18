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
        Schema::create('season_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('question')->nullable();
            $table->string('answer_source')->default('houseguests');
            $table->string('status')->default('draft')->index();
            $table->unsignedSmallInteger('position');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('locks_at')->nullable()->index();
            $table->unsignedSmallInteger('prediction_min_selections')->default(1);
            $table->unsignedSmallInteger('prediction_max_selections')->default(1);
            $table->unsignedSmallInteger('result_min_selections')->default(1);
            $table->unsignedSmallInteger('result_max_selections')->default(1);
            $table->timestamp('options_locked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['season_round_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_events');
    }
};
