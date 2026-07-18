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
        Schema::create('legacy_backup_restore_attestations', function (Blueprint $table) {
            $table->id();
            $table->string('authority_marker', 32);
            $table->string('environment', 128);
            $table->string('restore_target');
            $table->char('backup_sha256', 64);
            $table->string('evidence_path');
            $table->char('evidence_sha256', 64)->unique();
            $table->json('integrity_checks');
            $table->string('attested_by');
            $table->timestamp('verified_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('authority_marker')
                ->references('marker')
                ->on('canonical_flow_authorities')
                ->restrictOnDelete();
            $table->index(['authority_marker', 'verified_at'], 'legacy_backup_authority_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_backup_restore_attestations');
    }
};
