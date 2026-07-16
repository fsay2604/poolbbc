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
        Schema::create('pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('invite_code', 16)->unique();
            $table->string('timezone', 64)->default('America/Toronto');
            $table->string('status')->default('configuration')->index();
            $table->unsignedSmallInteger('max_members')->default(12);
            $table->unsignedTinyInteger('picks_per_member')->default(1);
            $table->string('draft_mode')->default('snake');
            $table->boolean('exclusive_draft')->default(true);
            $table->timestamp('registrations_closed_at')->nullable();
            $table->timestamps();

            $table->index(['season_id', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pools');
    }
};
