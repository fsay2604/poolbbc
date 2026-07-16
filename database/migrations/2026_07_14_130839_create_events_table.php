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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('question')->nullable();
            $table->string('mode')->default('prediction')->index();
            $table->string('answer_source')->default('houseguests');
            $table->string('status')->default('draft')->index();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('locks_at')->nullable()->index();
            $table->unsignedTinyInteger('prediction_min_selections')->default(1);
            $table->unsignedTinyInteger('prediction_max_selections')->default(1);
            $table->unsignedTinyInteger('result_min_selections')->default(1);
            $table->unsignedTinyInteger('result_max_selections')->default(1);
            $table->json('scoring_config');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'position']);
            $table->index(['pool_id', 'status', 'locks_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
