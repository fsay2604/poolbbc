<?php

namespace Tests\Feature;

use App\Actions\Events\PublishSeasonEventResult;
use App\Enums\PoolCompetitionMode;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEventPrediction;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonEventResult;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\SeasonRound;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyOfficialPoolMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_legacy_import_generates_a_deterministic_round_name_when_the_historical_week_name_is_missing(): void
    {
        User::factory()->admin()->create();
        $season = Season::factory()->create();
        $week = Week::factory()->for($season)->create([
            'number' => 4,
            'name' => null,
        ]);

        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])
            ->assertSuccessful();

        $round = SeasonRound::query()
            ->where('legacy_key', "legacy:week:{$week->id}")
            ->firstOrFail();

        $this->assertSame(__('Week :number', ['number' => 4]), $round->name);
    }

    public function test_shadow_json_is_machine_readable_and_names_each_week(): void
    {
        User::factory()->admin()->create();
        $season = Season::factory()->create();
        Week::factory()->for($season)->create([
            'number' => 4,
            'name' => null,
        ]);

        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])
            ->assertSuccessful();

        $exitCode = Artisan::call('legacy:shadow-compare', [
            '--season' => $season->id,
            '--json' => true,
        ]);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(__('Week :number', ['number' => 4]), $report[0]['members'][0]['weeks'][0]['week']);

        $pool = Pool::query()->where('legacy_key', "legacy:season:{$season->id}:pool")->firstOrFail();
        PointEntry::query()->create([
            'pool_member_id' => $pool->members()->firstOrFail()->id,
            'type' => 'legacy_adjustment',
            'points' => 1,
            'reason' => 'Machine-readable failure report test',
            'idempotency_key' => 'legacy:test:json-failure-report',
        ]);

        $failedExitCode = Artisan::call('legacy:shadow-compare', [
            '--season' => $season->id,
            '--json' => true,
        ]);

        $failedReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $failedExitCode);
        $this->assertSame(1, $failedReport[0]['unapproved_differences']);
    }

    public function test_legacy_import_can_add_a_late_participant_without_mutating_active_pool_rules(): void
    {
        User::factory()->admin()->create();
        $firstParticipant = User::factory()->create();
        $lateParticipant = User::factory()->create();
        $season = Season::factory()->create();
        $houseguest = Houseguest::factory()->for($season)->create();
        $week = Week::factory()->for($season)->create(['number' => 1]);
        $phase = $week->phases()->firstOrFail();
        $phase->update([
            'position' => 1,
            'type' => 'hoh',
            'config' => ['hoh_count' => 1],
        ]);
        $predictionPayload = [[
            'phase_id' => $phase->id,
            'position' => 1,
            'type' => 'hoh',
            'hoh_ids' => [$houseguest->id],
        ]];
        Prediction::factory()->for($week)->for($firstParticipant)->create([
            'phase_picks' => $predictionPayload,
            'confirmed_at' => now()->subHour(),
        ]);

        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();

        $pool = Pool::query()->where('legacy_key', "legacy:season:{$season->id}:pool")->sole();
        $this->assertSame(2, $pool->max_members);
        $this->assertSame(2, $pool->members()->count());
        $this->assertTrue($pool->poolEvents()->whereNull('rules_customized_at')->doesntExist());

        Prediction::factory()->for($week)->for($lateParticipant)->create([
            'phase_picks' => $predictionPayload,
            'confirmed_at' => now()->subMinutes(30),
        ]);

        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();

        $this->assertSame(3, $pool->fresh()->max_members);
        $this->assertSame(3, $pool->members()->count());
        $this->assertTrue($pool->members()->where('user_id', $lateParticipant->id)->exists());
    }

    public function test_legacy_import_preserves_history_and_is_idempotent(): void
    {
        $administrator = User::factory()->admin()->create();
        $user = User::factory()->create();
        $season = Season::factory()->create();
        $houseguest = Houseguest::factory()->for($season)->create(['name' => 'Alex']);
        $season->update(['winner_houseguest_id' => $houseguest->id]);
        $week = Week::factory()->for($season)->create([
            'number' => 1,
            'name' => 'Semaine 1',
            'is_locked' => true,
            'locked_at' => '2026-07-10 20:00:00',
        ]);
        $phase = $week->phases()->firstOrFail();
        $phase->update([
            'position' => 1,
            'type' => 'hoh',
            'config' => ['hoh_count' => 1],
        ]);
        $prediction = Prediction::factory()->for($week)->for($user)->create([
            'phase_picks' => [[
                'phase_id' => $phase->id,
                'position' => 1,
                'type' => 'hoh',
                'hoh_ids' => [$houseguest->id],
            ]],
            'confirmed_at' => '2026-07-09 18:30:00',
            'created_at' => '2026-07-08 12:00:00',
        ]);
        $outcome = WeekOutcome::factory()->for($week)->create([
            'phase_results' => [[
                'phase_id' => $phase->id,
                'position' => 1,
                'type' => 'hoh',
                'hoh_ids' => [$houseguest->id],
            ]],
            'last_admin_edited_by_user_id' => $administrator->id,
        ]);
        $predictionScore = PredictionScore::factory()->create([
            'prediction_id' => $prediction->id,
            'week_id' => $week->id,
            'user_id' => $user->id,
            'points' => 5,
        ]);
        $historicalDraft = Prediction::factory()->for($week)->for($administrator)->create([
            'phase_picks' => [],
            'confirmed_at' => null,
        ]);
        PredictionScore::factory()->create([
            'prediction_id' => $historicalDraft->id,
            'week_id' => $week->id,
            'user_id' => $administrator->id,
            'points' => 3,
        ]);
        $seasonPrediction = SeasonPrediction::factory()->submitted()->for($season)->for($user)->create([
            'winner_houseguest_id' => $houseguest->id,
            'confirmed_at' => '2026-07-01 12:00:00',
        ]);
        SeasonPredictionScore::query()->create([
            'season_prediction_id' => $seasonPrediction->id,
            'season_id' => $season->id,
            'user_id' => $user->id,
            'points' => 16,
            'breakdown' => [],
            'calculated_at' => now(),
        ]);

        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();
        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();

        $pool = Pool::query()->where('legacy_key', "legacy:season:{$season->id}:pool")->firstOrFail();
        $this->assertSame(PoolCompetitionMode::PredictionOnly, $pool->competition_mode);
        $this->assertDatabaseCount('pools', 1);
        $this->assertSame(2, $pool->members()->count());
        $this->assertDatabaseHas('season_rounds', ['legacy_key' => "legacy:week:{$week->id}"]);
        $this->assertDatabaseHas('season_events', ['legacy_key' => "legacy:week-phase:{$phase->id}:hoh_ids"]);
        $this->assertDatabaseHas('pool_event_predictions', [
            'legacy_key' => "legacy:prediction:{$prediction->id}:phase:{$phase->id}:hoh_ids",
            'submitted_at' => '2026-07-09 18:30:00',
        ]);
        $this->assertSame(17, PoolEventPrediction::query()->whereHas('poolEvent', fn ($query) => $query->where('pool_id', $pool->id))->count());
        $this->assertTrue(SeasonEventResult::query()->where('legacy_key', "legacy:outcome:{$outcome->id}:phase:{$phase->id}:hoh_ids")->exists());
        $this->assertSame(21, PointEntry::query()->whereIn('pool_member_id', $pool->members()->pluck('id'))->sum('points'));
        $this->assertDatabaseCount('point_entries', 9);

        $originalAdjustment = PointEntry::query()
            ->where('idempotency_key', "legacy:prediction-score-adjustment:{$predictionScore->id}")
            ->firstOrFail();
        $originalAdjustmentPoints = $originalAdjustment->points;
        $predictionScore->update(['points' => 6]);
        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();
        $this->assertSame($originalAdjustmentPoints, $originalAdjustment->fresh()->points);
        $this->assertDatabaseHas('point_entries', [
            'idempotency_key' => "legacy:prediction-score-adjustment:{$predictionScore->id}:revision:2",
            'points' => 1,
        ]);

        $predictionScore->update(['points' => 5]);
        $this->artisan('legacy:migrate-official-pools', ['--season' => $season->id])->assertSuccessful();
        $this->assertDatabaseHas('point_entries', [
            'idempotency_key' => "legacy:prediction-score-adjustment:{$predictionScore->id}:revision:3",
            'points' => -1,
        ]);
        $this->assertSame(21, PointEntry::query()->whereIn('pool_member_id', $pool->members()->pluck('id'))->sum('points'));

        $importedEvent = \App\Models\SeasonEvent::query()
            ->where('legacy_key', "legacy:week-phase:{$phase->id}:hoh_ids")
            ->with('options')
            ->firstOrFail();
        $adjustment = PointEntry::query()
            ->where('idempotency_key', 'like', 'legacy:prediction-score-adjustment:%')
            ->firstOrFail();
        $correctedResult = app(PublishSeasonEventResult::class)->handle(
            $importedEvent,
            $administrator,
            [$importedEvent->options->firstOrFail()->id],
            'Correction after import',
        );

        $this->assertSame(2, $correctedResult->version);
        $this->assertDatabaseHas('point_entries', [
            'idempotency_key' => "legacy-adjustment-reversal:{$adjustment->id}",
            'points' => -$adjustment->points,
        ]);
        $this->assertSame(17, PointEntry::query()->whereIn('pool_member_id', $pool->members()->pluck('id'))->sum('points'));

        $this->artisan('legacy:shadow-compare', ['--season' => $season->id])->assertFailed();
        $this->artisan('legacy:shadow-compare', [
            '--season' => $season->id,
            '--allow-draft-exclusions' => true,
        ])->assertSuccessful();

        PointEntry::query()->create([
            'pool_member_id' => $pool->members()->where('user_id', $user->id)->firstOrFail()->id,
            'type' => 'prediction',
            'points' => 99,
            'reason' => 'Post-cutover canonical activity',
            'idempotency_key' => 'canonical:post-cutover:test',
        ]);
        $this->artisan('legacy:shadow-compare', [
            '--season' => $season->id,
            '--allow-draft-exclusions' => true,
        ])->assertSuccessful();

        $weekEntry = PointEntry::query()
            ->where('type', 'prediction')
            ->whereHas('poolEvent.seasonEvent.round', fn ($query) => $query->where('legacy_key', "legacy:week:{$week->id}"))
            ->firstOrFail();
        $seasonEntry = PointEntry::query()
            ->where('type', 'prediction')
            ->whereHas('poolEvent.seasonEvent.round', fn ($query) => $query->where('legacy_key', "legacy:season:{$season->id}:predictions"))
            ->firstOrFail();
        $weekTamper = PointEntry::query()->create([
            'pool_member_id' => $weekEntry->pool_member_id,
            'pool_event_id' => $weekEntry->pool_event_id,
            'type' => 'legacy_adjustment',
            'points' => 1,
            'reason' => 'Shadow comparison week mismatch',
            'idempotency_key' => 'legacy:test:shadow-week-mismatch',
        ]);
        $seasonTamper = PointEntry::query()->create([
            'pool_member_id' => $seasonEntry->pool_member_id,
            'pool_event_id' => $seasonEntry->pool_event_id,
            'type' => 'legacy_adjustment',
            'points' => -1,
            'reason' => 'Shadow comparison season mismatch',
            'idempotency_key' => 'legacy:test:shadow-season-mismatch',
        ]);
        $this->artisan('legacy:shadow-compare', [
            '--season' => $season->id,
            '--allow-draft-exclusions' => true,
        ])->assertFailed();

        PointEntry::query()->create([
            'pool_member_id' => $weekTamper->pool_member_id,
            'pool_event_id' => $weekTamper->pool_event_id,
            'reverses_point_entry_id' => $weekTamper->id,
            'type' => 'reversal',
            'points' => -$weekTamper->points,
            'reason' => 'Shadow comparison test compensation',
            'idempotency_key' => 'legacy:test:shadow-week-compensation',
        ]);
        PointEntry::query()->create([
            'pool_member_id' => $seasonTamper->pool_member_id,
            'pool_event_id' => $seasonTamper->pool_event_id,
            'reverses_point_entry_id' => $seasonTamper->id,
            'type' => 'reversal',
            'points' => -$seasonTamper->points,
            'reason' => 'Shadow comparison test compensation',
            'idempotency_key' => 'legacy:test:shadow-season-compensation',
        ]);

        $entry = PointEntry::query()->where('idempotency_key', 'like', 'legacy:prediction-score-adjustment:%')->firstOrFail();
        $tamper = PointEntry::query()->create([
            'pool_member_id' => $entry->pool_member_id,
            'pool_event_id' => $entry->pool_event_id,
            'type' => 'legacy_adjustment',
            'points' => 1,
            'reason' => 'Shadow comparison adjustment mismatch',
            'idempotency_key' => 'legacy:test:shadow-adjustment-mismatch',
        ]);
        $this->artisan('legacy:shadow-compare', [
            '--season' => $season->id,
            '--allow-draft-exclusions' => true,
        ])->assertFailed();
        $this->assertModelExists($tamper);
        $this->assertSame(1, $tamper->fresh()->points);
    }

    public function test_shadow_and_retirement_gates_include_legacy_seasons_without_an_imported_pool(): void
    {
        $season = Season::factory()->create();
        Week::factory()->for($season)->create();

        $this->artisan('legacy:shadow-compare')->assertFailed();

        config()->set('legacy-flow.canonical_is_authoritative', true);
        config()->set('legacy-flow.observation_started_at', now()->subDays(30)->toIso8601String());
        $this->artisan('legacy:retirement-readiness')->assertFailed();
    }

    public function test_retirement_readiness_requires_fourteen_real_days_regular_shadow_evidence_and_an_attested_backup_restore(): void
    {
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);

        config()->set('legacy-flow.cutover_enabled', false);
        config()->set('legacy-flow.canonical_is_authoritative', true);
        config()->set('legacy-flow.minimum_observation_days', 0);
        config()->set('legacy-flow.observation_started_at', $startedAt->copy()->subDays(30)->toIso8601String());

        $this->artisan('legacy:retirement-readiness')->assertFailed();

        config()->set('legacy-flow.observation_started_at', $startedAt->toIso8601String());
        $this->artisan('legacy:shadow-compare', [
            '--allow-draft-exclusions' => true,
            '--record' => true,
        ])->assertSuccessful();

        for ($day = 1; $day <= 14; $day++) {
            $this->travel(1)->days();
            $this->artisan('legacy:shadow-compare', [
                '--allow-draft-exclusions' => true,
                '--record' => true,
            ])->assertSuccessful();
        }

        $this->artisan('legacy:retirement-readiness')->assertFailed();

        Storage::fake('local');
        $evidencePath = Storage::disk('local')->path('legacy-backup-restore.json');
        $evidence = [
            'environment' => 'isolated-restore',
            'restore_target' => 'poolbbc-restore-verification',
            'backup_sha256' => str_repeat('a', 64),
            'integrity_checks' => [
                'schema' => true,
                'row_counts' => true,
                'application_smoke' => false,
            ],
        ];
        Storage::disk('local')->put('legacy-backup-restore.json', json_encode($evidence, JSON_THROW_ON_ERROR));

        $this->artisan('legacy:attest-backup-restore', [
            'evidence' => $evidencePath,
            '--attested-by' => 'Release Operator',
        ])->assertFailed();

        $evidence['integrity_checks']['application_smoke'] = true;
        Storage::disk('local')->put('legacy-backup-restore.json', json_encode($evidence, JSON_THROW_ON_ERROR));

        $this->artisan('legacy:attest-backup-restore', [
            'evidence' => $evidencePath,
            '--attested-by' => 'Release Operator',
        ])->assertSuccessful();

        config()->set('legacy-flow.canonical_is_authoritative', false);

        $this->artisan('legacy:retirement-readiness')
            ->expectsOutputToContain('Observation >= 14 days')
            ->assertSuccessful();
    }
}
