<?php

namespace Tests\Feature;

use App\Models\PointEntry;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ImportedScoreAdjustmentRetirementMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_is_blocked_when_operational_evidence_is_missing(): void
    {
        $adjustment = $this->createAdjustment();

        try {
            $this->dataMigration()->up();
            $this->fail('Retirement should fail closed without observation and restoration evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy retirement is blocked', $exception->getMessage());
        }

        $this->assertModelExists($adjustment);
        $this->assertDatabaseMissing('point_entries', [
            'idempotency_key' => "round-reconciliation:source-entry:{$adjustment->id}:canonical",
        ]);
    }

    public function test_verified_adjustments_are_converted_strictly_without_changing_totals(): void
    {
        $evidencePath = $this->installValidRetirementEvidence();

        try {
            $adjustment = $this->createAdjustment(5);
            $totalBefore = PointEntry::query()
                ->where('pool_member_id', $adjustment->pool_member_id)
                ->sum('points');

            $migration = $this->dataMigration();
            $migration->up();

            $this->assertDatabaseHas('point_entries', [
                'reverses_point_entry_id' => $adjustment->id,
                'type' => 'reversal',
                'points' => -5,
                'idempotency_key' => "round-reconciliation:source-entry:{$adjustment->id}:retirement",
            ]);
            $this->assertDatabaseHas('point_entries', [
                'pool_member_id' => $adjustment->pool_member_id,
                'pool_event_id' => $adjustment->pool_event_id,
                'season_event_result_id' => null,
                'type' => 'round_reconciliation',
                'points' => 5,
                'idempotency_key' => "round-reconciliation:source-entry:{$adjustment->id}:canonical",
            ]);
            $this->assertSame(
                $totalBefore,
                (int) PointEntry::query()
                    ->where('pool_member_id', $adjustment->pool_member_id)
                    ->sum('points'),
            );

            $entryCount = PointEntry::query()->count();
            $migration->up();
            $this->assertSame($entryCount, PointEntry::query()->count());
        } finally {
            $this->removeRetirementEvidence($evidencePath);
        }
    }

    public function test_ambiguous_partial_reversals_abort_without_appending_a_reconciliation(): void
    {
        $evidencePath = $this->installValidRetirementEvidence();

        try {
            $adjustment = $this->createAdjustment(5);
            PointEntry::query()->create([
                'pool_member_id' => $adjustment->pool_member_id,
                'pool_event_id' => $adjustment->pool_event_id,
                'reverses_point_entry_id' => $adjustment->id,
                'type' => 'reversal',
                'points' => -2,
                'reason' => 'Incomplete correction.',
                'idempotency_key' => 'round-reconciliation:test-partial-reversal',
            ]);

            try {
                $this->dataMigration()->up();
                $this->fail('An ambiguous partial reversal should abort retirement.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('ambiguous or partial reversal history', $exception->getMessage());
            }

            $this->assertDatabaseMissing('point_entries', [
                'idempotency_key' => "round-reconciliation:source-entry:{$adjustment->id}:canonical",
            ]);
        } finally {
            $this->removeRetirementEvidence($evidencePath);
        }
    }

    public function test_future_dated_observations_cannot_satisfy_the_retirement_gate(): void
    {
        $evidencePath = $this->installValidRetirementEvidence();

        try {
            DB::table('legacy_shadow_observations')->insert([
                'authority_marker' => 'canonical',
                'environment' => app()->environment(),
                'unapproved_differences' => 0,
                'report_hash' => hash('sha256', 'future-observation'),
                'observed_at' => now()->addHour(),
                'created_at' => now(),
            ]);
            $this->createAdjustment();

            try {
                $this->dataMigration()->up();
                $this->fail('A future-dated shadow observation should fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('future timestamp', $exception->getMessage());
            }
        } finally {
            $this->removeRetirementEvidence($evidencePath);
        }
    }

    public function test_future_dated_restore_attestations_cannot_satisfy_the_retirement_gate(): void
    {
        $evidencePath = $this->installValidRetirementEvidence();

        try {
            DB::table('legacy_backup_restore_attestations')->update([
                'verified_at' => now()->addHour(),
            ]);
            $this->createAdjustment();

            try {
                $this->dataMigration()->up();
                $this->fail('A future-dated restoration attestation should fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('no verified backup restoration attestation', $exception->getMessage());
            }
        } finally {
            $this->removeRetirementEvidence($evidencePath);
        }
    }

    public function test_sqlite_schema_retirement_can_be_safely_retried_after_success(): void
    {
        $migration = $this->schemaMigration();
        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasTable('weeks'));
        $this->assertFalse(Schema::hasColumn('seasons', 'prediction_locks_at'));
        $this->assertFalse(Schema::hasColumn('season_event_results', 'legacy_key'));

        $triggerNames = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [
                'season_event_results_immutable_update',
                'season_event_results_immutable_delete',
                'season_event_results_published_at_consistent_insert',
            ])
            ->pluck('name');

        $this->assertCount(3, $triggerNames);
        $this->assertDatabaseMissing('sqlite_master', [
            'name' => 'season_event_results_retirement_guard_update',
        ]);
    }

    private function createAdjustment(int $points = 3): PointEntry
    {
        $poolEvent = PoolEvent::factory()->create();
        $member = PoolMember::factory()->for($poolEvent->pool)->create();

        return PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'pool_event_id' => $poolEvent->id,
            'type' => 'legacy_adjustment',
            'points' => $points,
            'reason' => 'Historical imported score reconciliation.',
            'idempotency_key' => 'retirement-test:'.Str::uuid(),
        ]);
    }

    private function installValidRetirementEvidence(): string
    {
        Schema::create('canonical_flow_authorities', function (Blueprint $table): void {
            $table->string('marker', 32)->primary();
            $table->timestamp('activated_at');
            $table->timestamps();
        });
        Schema::create('legacy_shadow_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('authority_marker', 32);
            $table->string('environment', 64);
            $table->unsignedInteger('unapproved_differences');
            $table->char('report_hash', 64);
            $table->timestamp('observed_at');
            $table->timestamp('created_at');
        });
        Schema::create('legacy_backup_restore_attestations', function (Blueprint $table): void {
            $table->id();
            $table->string('authority_marker', 32);
            $table->string('environment', 128);
            $table->string('restore_target');
            $table->char('backup_sha256', 64);
            $table->string('evidence_path');
            $table->char('evidence_sha256', 64)->unique();
            $table->json('integrity_checks');
            $table->string('attested_by');
            $table->timestamp('verified_at');
            $table->timestamp('created_at');
        });

        $observationStartedAt = now()->subDays(15);
        DB::table('canonical_flow_authorities')->insert([
            'marker' => 'canonical',
            'activated_at' => $observationStartedAt->copy()->subHour(),
            'created_at' => $observationStartedAt->copy()->subHour(),
            'updated_at' => $observationStartedAt->copy()->subHour(),
        ]);

        for ($day = 0; $day <= 15; $day++) {
            $observedAt = $observationStartedAt->copy()->addDays($day);
            DB::table('legacy_shadow_observations')->insert([
                'authority_marker' => 'canonical',
                'environment' => app()->environment(),
                'unapproved_differences' => 0,
                'report_hash' => hash('sha256', "observation-{$day}"),
                'observed_at' => $observedAt,
                'created_at' => $observedAt,
            ]);
        }

        $evidencePath = storage_path('framework/testing/retirement-evidence-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($evidencePath));
        File::put($evidencePath, json_encode([
            'environment' => app()->environment(),
            'integrity_checks' => [
                'schema' => true,
                'row_counts' => true,
                'application_smoke' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        DB::table('legacy_backup_restore_attestations')->insert([
            'authority_marker' => 'canonical',
            'environment' => app()->environment(),
            'restore_target' => 'retirement-test-restore',
            'backup_sha256' => str_repeat('a', 64),
            'evidence_path' => $evidencePath,
            'evidence_sha256' => hash_file('sha256', $evidencePath),
            'integrity_checks' => json_encode([
                'schema' => true,
                'row_counts' => true,
                'application_smoke' => true,
            ], JSON_THROW_ON_ERROR),
            'attested_by' => 'Automated retirement test',
            'verified_at' => $observationStartedAt->copy()->addDays(14)->addHour(),
            'created_at' => $observationStartedAt->copy()->addDays(14)->addHour(),
        ]);

        return $evidencePath;
    }

    private function removeRetirementEvidence(string $evidencePath): void
    {
        File::delete($evidencePath);
        Schema::dropIfExists('legacy_shadow_observations');
        Schema::dropIfExists('legacy_backup_restore_attestations');
        Schema::dropIfExists('canonical_flow_authorities');
    }

    private function dataMigration(): Migration
    {
        return require database_path('migrations/2026_07_18_141210_retire_imported_score_adjustments.php');
    }

    private function schemaMigration(): Migration
    {
        return require database_path('migrations/2026_07_18_141224_drop_legacy_prediction_domain.php');
    }
}
