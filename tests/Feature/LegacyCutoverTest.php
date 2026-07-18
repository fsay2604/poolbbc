<?php

namespace Tests\Feature;

use App\Actions\Predictions\RecalculateAllScores;
use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Actions\Predictions\ScoreWeek;
use App\Actions\Predictions\StoreSeasonPrediction;
use App\Actions\Predictions\StoreWeekPrediction;
use App\Enums\PoolStatus;
use App\Models\CanonicalFlowAuthority;
use App\Models\LegacyBackupRestoreAttestation;
use App\Models\LegacyShadowObservation;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Support\LegacyFlowAuthority;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LegacyCutoverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_historical_routes_redirect_to_equivalent_official_pool_pages_without_loops(): void
    {
        [$pool, $member, $week] = $this->cutoverContext();
        config()->set('legacy-flow.cutover_enabled', true);

        $this->actingAs($member->user)->get(route('weeks.index'))
            ->assertRedirect(route('pools.predictions', ['pool' => $pool, 'migrated' => 1]));
        $this->actingAs($member->user)->get(route('weeks.show', $week))
            ->assertRedirect(route('pools.predictions', ['pool' => $pool, 'migrated' => 1]));
        $this->actingAs($member->user)->get(route('season.prediction'))
            ->assertRedirect(route('pools.predictions', ['pool' => $pool, 'migrated' => 1]));
        $this->actingAs($member->user)->get(route('leaderboard'))
            ->assertRedirect(route('pools.leaderboard', ['pool' => $pool, 'migrated' => 1]));

        $this->actingAs($member->user)->get(route('pools.predictions', $pool))->assertOk();
    }

    public function test_cutover_blocks_legacy_writes_and_disabling_the_flag_restores_the_rollback_path(): void
    {
        [$pool, $member, $week] = $this->cutoverContext();
        $season = $pool->season;
        config()->set('legacy-flow.cutover_enabled', true);

        try {
            app(StoreWeekPrediction::class)->handle($week, $member->user, [], false);
            $this->fail('Legacy week writes should be blocked after cutover.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        try {
            app(StoreSeasonPrediction::class)->handle($season, $member->user, [], false);
            $this->fail('Legacy season writes should be blocked after cutover.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        config()->set('legacy-flow.cutover_enabled', false);
        $prediction = app(StoreWeekPrediction::class)->handle($week, $member->user, [], false);
        $this->assertNotNull($prediction->id);
    }

    public function test_system_administrator_legacy_pages_redirect_to_official_administration(): void
    {
        [, , $week] = $this->cutoverContext();
        $administrator = User::factory()->admin()->create();
        config()->set('legacy-flow.cutover_enabled', true);

        $this->actingAs($administrator)
            ->get(route('admin.weeks.outcome', $week))
            ->assertRedirect(route('admin.official-rounds', ['migrated' => 1]));
    }

    public function test_authoritative_canonical_marker_prevents_an_unsafe_legacy_rollback_after_cutover(): void
    {
        [$pool, $member, $week] = $this->cutoverContext();
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();

        $this->actingAs($member->user)
            ->get(route('weeks.show', $week))
            ->assertRedirect(route('pools.predictions', ['pool' => $pool, 'migrated' => 1]));

        $this->expectException(ValidationException::class);
        app(StoreWeekPrediction::class)->handle($week, $member->user, [], false);
    }

    public function test_authoritative_database_marker_hides_legacy_week_navigation_without_environment_flags(): void
    {
        $administrator = User::factory()->admin()->create();
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();

        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee(route('admin.official-rounds'), false)
            ->assertDontSee(route('admin.weeks.index'), false);
    }

    public function test_legacy_import_is_rejected_after_canonical_authority(): void
    {
        [$pool] = $this->cutoverContext();
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();

        $this->artisan('legacy:migrate-official-pools', ['--season' => $pool->season_id])
            ->assertFailed();

        $this->assertDatabaseCount('season_rounds', 0);
        $this->assertSame($pool->name, $pool->fresh()->name);
    }

    public function test_authoritative_dashboard_uses_only_accessible_canonical_pool_summaries(): void
    {
        [$pool] = $this->cutoverContext();
        $administrator = User::factory()->admin()->create();
        PoolMember::factory()->for($pool)->for($administrator)->create(['draft_position' => 2]);
        $otherPool = Pool::factory()->predictionOnly()->create(['season_id' => $pool->season_id]);
        PoolMember::factory()->for($otherPool)->create();
        config()->set('legacy-flow.canonical_is_authoritative', true);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Aperçu de vos pools')
            ->assertSee($pool->name)
            ->assertDontSee($otherPool->name)
            ->assertDontSee(__('Statistics'))
            ->assertDontSee(__('Recalculate Scores'))
            ->assertDontSee(route('predictions.show', $administrator));

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'prediction_scores') || preg_match('/\bweeks\b/', $query) === 1,
        ));
    }

    public function test_canonical_authority_blocks_every_direct_legacy_scoring_entry_point(): void
    {
        [$pool, , $week] = $this->cutoverContext();
        $administrator = User::factory()->admin()->create();
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();

        $attempts = [
            fn () => app(ScoreWeek::class)->run($week, $administrator),
            fn () => app(ScoreSeasonPredictions::class)->run($pool->season, $administrator),
            fn () => app(RecalculateAllScores::class)->run($pool->season, $administrator),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('A direct legacy scoring entry point should be read-only after canonical authority.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('scoring', $exception->errors());
            }
        }

        Livewire::actingAs($administrator)
            ->test('admin.recalculate')
            ->call('recalculate')
            ->assertHasErrors(['scoring']);

        $this->assertDatabaseCount('prediction_scores', 0);
        $this->assertDatabaseCount('season_prediction_scores', 0);
    }

    public function test_authoritative_config_is_persisted_as_an_immutable_one_way_database_marker(): void
    {
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', true);

        $this->assertTrue(app(LegacyFlowAuthority::class)->isAuthoritative());
        $this->assertDatabaseHas('canonical_flow_authorities', [
            'marker' => CanonicalFlowAuthority::MARKER,
        ]);

        config()->set('legacy-flow.canonical_is_authoritative', false);

        $this->assertTrue(app(LegacyFlowAuthority::class)->isAuthoritative());

        $authority = CanonicalFlowAuthority::query()->findOrFail(CanonicalFlowAuthority::MARKER);
        try {
            $authority->delete();
            $this->fail('The canonical authority marker must not be removable through the application model.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be removed', $exception->getMessage());
        }

        $this->assertModelExists($authority);
    }

    public function test_authority_marker_and_retirement_evidence_are_immutable_at_the_database_level(): void
    {
        config()->set('legacy-flow.canonical_is_authoritative', false);
        $authority = app(LegacyFlowAuthority::class)->activate();
        $observation = LegacyShadowObservation::factory()->create();
        $attestation = LegacyBackupRestoreAttestation::factory()->create();

        $mutations = [
            fn () => DB::table('canonical_flow_authorities')->where('marker', $authority->marker)->update(['activated_at' => now()->subDay()]),
            fn () => DB::table('canonical_flow_authorities')->where('marker', $authority->marker)->delete(),
            fn () => DB::table('legacy_shadow_observations')->where('id', $observation->id)->update(['unapproved_differences' => 99]),
            fn () => DB::table('legacy_shadow_observations')->where('id', $observation->id)->delete(),
            fn () => DB::table('legacy_backup_restore_attestations')->where('id', $attestation->id)->update(['attested_by' => 'Tampered']),
            fn () => DB::table('legacy_backup_restore_attestations')->where('id', $attestation->id)->delete(),
        ];

        foreach ($mutations as $mutation) {
            try {
                $mutation();
                $this->fail('Database-level append-only evidence must reject bulk mutations.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue(app(LegacyFlowAuthority::class)->isAuthoritative());
        $this->assertModelExists($authority);
        $this->assertModelExists($observation);
        $this->assertModelExists($attestation);
    }

    public function test_authority_blocks_scoring_config_rollback_before_mariadb_can_run_destructive_ddl(): void
    {
        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();
        $migration = require database_path('migrations/2026_07_17_022147_add_scoring_config_to_pools_table.php');

        $this->assertTrue(Schema::hasColumn('pools', 'scoring_config'));

        try {
            Event::dispatch(new MigrationStarted($migration, 'down'));
            $this->fail('Protected canonical migration rollback should be rejected before its down method runs.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('add_scoring_config_to_pools_table.php', $exception->getMessage());
        }

        try {
            $migration->down();
            $this->fail('The scoring configuration migration must also defend direct down invocations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('scoring configuration', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('pools', 'scoring_config'));
    }

    public function test_authority_blocks_rollback_of_every_latest_canonical_integrity_migration(): void
    {
        config()->set('legacy-flow.canonical_is_authoritative', false);
        app(LegacyFlowAuthority::class)->activate();

        $migrationFiles = [
            '2026_07_17_054300_enforce_event_type_scope_key_consistency.php',
            '2026_07_17_071138_enforce_canonical_flow_authority_immutability.php',
            '2026_07_17_071642_create_legacy_shadow_observations_table.php',
            '2026_07_17_071644_create_legacy_backup_restore_attestations_table.php',
            '2026_07_17_071645_enforce_legacy_retirement_evidence_immutability.php',
        ];

        foreach ($migrationFiles as $migrationFile) {
            $migration = require database_path("migrations/{$migrationFile}");

            try {
                Event::dispatch(new MigrationStarted($migration, 'down'));
                $this->fail("Canonical migration [{$migrationFile}] rollback should be blocked.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($migrationFile, $exception->getMessage());
            }
        }
    }

    /** @return array{Pool, PoolMember, Week} */
    private function cutoverContext(): array
    {
        $season = Season::factory()->create([
            'is_active' => true,
            'prediction_opens_at' => now()->subHour(),
            'prediction_locks_at' => now()->addHour(),
        ]);
        $week = Week::factory()->for($season)->create(['is_locked' => false, 'auto_lock_at' => now()->addHour()]);
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'status' => PoolStatus::Active,
            'legacy_key' => "legacy:season:{$season->id}:pool",
        ]);
        $member = PoolMember::factory()->for($pool)->create();

        return [$pool->load('season'), $member->load('user'), $week];
    }
}
