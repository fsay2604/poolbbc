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
        Schema::create('pool_score_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_member_id')->constrained()->cascadeOnDelete();
            $table->integer('total_points')->default(0);
            $table->foreignId('last_point_entry_id')->nullable()->constrained('point_entries')->nullOnDelete();
            $table->timestamp('rebuilt_at');
            $table->timestamps();

            $table->unique(['pool_id', 'pool_member_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pool_score_projections');
    }
};
