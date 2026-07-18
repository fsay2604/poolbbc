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
        Schema::create('season_event_result_scoring_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_event_result_id')
                ->constrained(indexName: 'season_result_receipt_result_fk')
                ->cascadeOnDelete();
            $table->foreignId('pool_event_id')
                ->constrained(indexName: 'season_result_receipt_pool_event_fk')
                ->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['season_event_result_id', 'pool_event_id'],
                'season_result_scoring_receipt_unique',
            );
            $table->index(
                ['season_event_result_id', 'completed_at'],
                'season_result_receipt_completion_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_event_result_scoring_receipts');
    }
};
