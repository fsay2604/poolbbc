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
        Schema::create('pool_event_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_member_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['pool_event_id', 'pool_member_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pool_event_predictions');
    }
};
