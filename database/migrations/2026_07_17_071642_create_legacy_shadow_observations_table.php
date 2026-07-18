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
        Schema::create('legacy_shadow_observations', function (Blueprint $table) {
            $table->id();
            $table->string('authority_marker', 32);
            $table->string('environment', 64);
            $table->unsignedInteger('unapproved_differences');
            $table->char('report_hash', 64);
            $table->timestamp('observed_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('authority_marker')
                ->references('marker')
                ->on('canonical_flow_authorities')
                ->restrictOnDelete();
            $table->index(['authority_marker', 'environment', 'observed_at'], 'legacy_shadow_authority_environment_observed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_shadow_observations');
    }
};
