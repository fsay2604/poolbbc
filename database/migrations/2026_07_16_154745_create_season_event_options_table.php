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
        Schema::create('season_event_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('houseguest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('value');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_none')->default(false);
            $table->timestamps();

            $table->unique(['season_event_id', 'value']);
            $table->unique(['season_event_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_event_options');
    }
};
