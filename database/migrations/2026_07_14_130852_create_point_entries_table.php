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
        Schema::create('point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_prediction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_point_entry_id')->nullable()->constrained('point_entries')->nullOnDelete();
            $table->string('type');
            $table->integer('points');
            $table->string('reason');
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['pool_member_id', 'created_at']);
            $table->index(['event_id', 'event_result_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_entries');
    }
};
