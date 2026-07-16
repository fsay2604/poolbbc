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
        Schema::create('draft_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_member_id')->constrained()->restrictOnDelete();
            $table->foreignId('houseguest_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->unsignedInteger('pick_number');
            $table->unsignedTinyInteger('exclusive_claim')->nullable();
            $table->timestamp('picked_at');
            $table->timestamps();

            $table->unique(['draft_id', 'pick_number']);
            $table->unique(['draft_id', 'pool_member_id', 'houseguest_id']);
            $table->unique(['pool_id', 'houseguest_id', 'exclusive_claim']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draft_picks');
    }
};
