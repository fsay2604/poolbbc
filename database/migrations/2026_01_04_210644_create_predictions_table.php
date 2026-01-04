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
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('hoh_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('nominee_1_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('nominee_2_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('veto_winner_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->boolean('veto_used')->nullable();
            $table->foreignId('saved_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('replacement_nominee_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();
            $table->foreignId('evicted_houseguest_id')->nullable()->constrained('houseguests')->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable()->index();

            $table->foreignId('last_admin_edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_admin_edited_at')->nullable();
            $table->unsignedInteger('admin_edit_count')->default(0);

            $table->unique(['week_id', 'user_id']);
            $table->index(['user_id', 'week_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
