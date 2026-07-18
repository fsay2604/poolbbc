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
        Schema::create('season_event_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('season_event_results')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('published')->index();
            $table->text('correction_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['season_event_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_event_results');
    }
};
