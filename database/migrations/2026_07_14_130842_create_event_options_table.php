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
        Schema::create('event_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('houseguest_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('value');
            $table->unsignedSmallInteger('position')->default(1);
            $table->boolean('is_none')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_options');
    }
};
