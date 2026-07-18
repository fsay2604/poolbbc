<?php

namespace Tests\Feature;

use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Actions\Predictions\ScoreWeek;
use App\Actions\Predictions\StoreSeasonPrediction;
use App\Actions\Predictions\StoreWeekPrediction;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\SeasonPredictionScore;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacySubmissionDeadlineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_submitted_week_prediction_remains_editable_until_exact_deadline(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        $user = User::factory()->create();
        $week = Week::factory()->create(['is_locked' => false, 'auto_lock_at' => now()->addSecond()]);
        $action = app(StoreWeekPrediction::class);

        $prediction = $action->handle($week, $user, [['type' => 'hoh', 'hoh_ids' => [1]]], true);
        $submittedAt = $prediction->confirmed_at;
        $prediction = $action->handle($week, $user, [['type' => 'hoh', 'hoh_ids' => [2]]], false);

        $this->assertNotNull($prediction->confirmed_at);
        $this->assertTrue($prediction->confirmed_at->equalTo($submittedAt));

        Carbon::setTestNow('2026-07-16 12:00:01');
        $this->expectException(ValidationException::class);
        $action->handle($week, $user, [], false);
    }

    public function test_season_prediction_uses_the_server_deadline_and_preserves_submission(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        $user = User::factory()->create();
        $season = Season::factory()->create([
            'prediction_opens_at' => now()->subHour(),
            'prediction_locks_at' => now()->addSecond(),
        ]);
        $action = app(StoreSeasonPrediction::class);

        $prediction = $action->handle($season, $user, ['top_6_houseguest_ids' => []], true);
        $action->handle($season, $user, ['top_6_houseguest_ids' => [1]], false);
        $this->assertNotNull($prediction->fresh()->confirmed_at);

        Carbon::setTestNow('2026-07-16 12:00:01');
        $this->expectException(ValidationException::class);
        $action->handle($season, $user, [], false);
    }

    public function test_drafts_are_never_scored_and_stale_draft_scores_are_removed(): void
    {
        $admin = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $winner = Houseguest::factory()->for($season)->create();
        $season->update(['winner_houseguest_id' => $winner->id]);
        $week = Week::factory()->for($season)->create();
        WeekOutcome::factory()->for($week)->create(['phase_results' => []]);

        $weeklyDraft = Prediction::factory()->for($week)->create();
        PredictionScore::factory()->create([
            'prediction_id' => $weeklyDraft->id,
            'week_id' => $week->id,
            'user_id' => $weeklyDraft->user_id,
        ]);

        $seasonDraft = SeasonPrediction::factory()->for($season)->create();
        SeasonPredictionScore::query()->create([
            'season_prediction_id' => $seasonDraft->id,
            'season_id' => $season->id,
            'user_id' => $seasonDraft->user_id,
            'points' => 99,
            'breakdown' => [],
            'calculated_at' => now(),
        ]);

        app(ScoreWeek::class)->run($week, $admin);
        app(ScoreSeasonPredictions::class)->run($season, $admin);

        $this->assertDatabaseMissing('prediction_scores', ['prediction_id' => $weeklyDraft->id]);
        $this->assertDatabaseMissing('season_prediction_scores', ['season_prediction_id' => $seasonDraft->id]);
    }

    public function test_all_stale_season_scores_are_removed_when_a_correction_clears_every_outcome(): void
    {
        $season = Season::factory()->create();
        $prediction = SeasonPrediction::factory()->submitted()->for($season)->create();
        SeasonPredictionScore::query()->create([
            'season_prediction_id' => $prediction->id,
            'season_id' => $season->id,
            'user_id' => $prediction->user_id,
            'points' => 42,
            'breakdown' => [],
            'calculated_at' => now(),
        ]);

        app(ScoreSeasonPredictions::class)->run($season);

        $this->assertDatabaseMissing('season_prediction_scores', ['season_prediction_id' => $prediction->id]);
    }
}
