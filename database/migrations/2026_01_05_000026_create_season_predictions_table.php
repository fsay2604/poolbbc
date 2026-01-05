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
        Schema::create('season_predictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('winner_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('first_evicted_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();

            $table->json('top_6_houseguest_ids')->nullable();

            $table->timestamp('confirmed_at')->nullable()->index();

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
        Schema::dropIfExists('season_predictions');
    }
};
