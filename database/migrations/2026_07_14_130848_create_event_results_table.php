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
        Schema::create('event_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('event_results')->nullOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status')->default('published')->index();
            $table->text('correction_reason')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['event_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_results');
    }
};
