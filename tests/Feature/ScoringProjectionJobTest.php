<?php

namespace Tests\Feature;

use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Actions\Pools\TransitionPoolStatus;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolStatus;
use App\Jobs\FinalizeSeasonEventResultJob;
use App\Jobs\ScoreSeasonEventResultJob;
use App\Models\EventType;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\PoolScoreProjection;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScoringProjectionJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_publication_dispatches_one_unique_replayable_job_per_pool_and_rebuilds_projection(): void
    {
        Bus::fake();
        [$event, $option, $poolEvent, $member] = $this->scoringContext();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $result = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);

        Bus::assertChained([
            new ScoreSeasonEventResultJob($result->id, $poolEvent->id),
            new FinalizeSeasonEventResultJob($result->id),
        ]);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertSame('pending', $result->fresh()->status);
        $this->assertSame(EventStatus::ResultEntered, $event->fresh()->status);

        Cache::put(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id), 'stale');
        $job = new ScoreSeasonEventResultJob($result->id, $poolEvent->id);
        $job->handle(app(ScoreSeasonEventResult::class));
        $job->handle(app(ScoreSeasonEventResult::class));

        $this->assertDatabaseCount('point_entries', 1);
        $this->assertSame('pending', $result->fresh()->status);
        $this->assertSame('stale', Cache::get(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id)));
        $this->assertDatabaseMissing('pool_score_projections', ['pool_member_id' => $member->id]);

        Cache::forget(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id));
        $pendingLeaderboard = app(BuildPoolLeaderboard::class)->handle($poolEvent->pool);
        $this->assertSame(0, $pendingLeaderboard->firstWhere('member.id', $member->id)['total_points']);
        $this->assertNotNull(Cache::get(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id)));

        (new FinalizeSeasonEventResultJob($result->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertDatabaseCount('point_entries', 1);
        $entry = PointEntry::query()->firstOrFail();
        $projection = PoolScoreProjection::query()->where('pool_member_id', $member->id)->firstOrFail();
        $this->assertSame($entry->points, $projection->total_points);
        $this->assertSame($entry->id, $projection->last_point_entry_id);
        $this->assertNull(Cache::get(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id)));
        $this->assertSame('published', $result->fresh()->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
    }

    public function test_failed_finalization_can_be_retried_safely_and_idempotently(): void
    {
        Bus::fake();
        [$event, $option, $poolEvent, $member] = $this->scoringContext();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $result = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);
        (new ScoreSeasonEventResultJob($result->id, $poolEvent->id))
            ->handle(app(ScoreSeasonEventResult::class));
        $job = new FinalizeSeasonEventResultJob($result->id);
        $job->failed(new \RuntimeException('Simulated terminal finalization failure.'));

        $this->assertSame('failed', $result->fresh()->status);
        $this->assertSame(EventStatus::ResultEntered, $event->fresh()->status);
        $this->assertNull($result->fresh()->published_at);

        $job->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $publishedAt = $result->fresh()->published_at;
        $this->assertNotNull($publishedAt);
        $this->assertSame('published', $result->fresh()->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame(
            PointEntry::query()->where('pool_event_id', $poolEvent->id)->sum('points'),
            PoolScoreProjection::query()->where('pool_member_id', $member->id)->value('total_points'),
        );

        $job->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertTrue($publishedAt->equalTo($result->fresh()->published_at));
        $this->assertDatabaseCount('point_entries', 1);
    }

    public function test_official_correction_updates_active_and_completed_pools_without_reopening_them(): void
    {
        Bus::fake();
        [$event, $firstOption, $activePoolEvent, $activeMember] = $this->scoringContext();
        $secondOption = $event->options()->whereKeyNot($firstOption->id)->firstOrFail();
        $activePool = $activePoolEvent->pool;
        $activePool->update(['status' => PoolStatus::Active]);

        $completedOwner = User::factory()->create();
        $completedPool = Pool::factory()->predictionOnly()->create([
            'season_id' => $event->round->season_id,
            'owner_id' => $completedOwner->id,
            'status' => PoolStatus::Active,
        ]);
        $completedMember = PoolMember::factory()->for($completedPool)->for($completedOwner)->create([
            'role' => PoolMemberRole::Owner,
        ]);
        $completedPoolEvent = PoolEvent::factory()->create([
            'pool_id' => $completedPool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
            'scoring_config' => $activePoolEvent->scoring_config,
        ]);
        app(SubmitPoolEventPrediction::class)->handle($completedPoolEvent, $completedMember, [$firstOption->id]);

        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);
        $firstResult = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$firstOption->id]);

        foreach ([$activePoolEvent, $completedPoolEvent] as $poolEvent) {
            (new ScoreSeasonEventResultJob($firstResult->id, $poolEvent->id))
                ->handle(app(ScoreSeasonEventResult::class));
        }
        (new FinalizeSeasonEventResultJob($firstResult->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertSame(7, PointEntry::query()->where('pool_member_id', $activeMember->id)->sum('points'));
        $this->assertSame(7, PointEntry::query()->where('pool_member_id', $completedMember->id)->sum('points'));

        $completedPool = app(TransitionPoolStatus::class)->handle(
            $completedPool,
            $completedOwner,
            PoolStatus::Completed,
        );
        $correction = app(PublishSeasonEventResult::class)->handle(
            $event->fresh(),
            $administrator,
            [$secondOption->id],
            'Correction officielle après la fin du pool.',
        );

        foreach ([$activePoolEvent, $completedPoolEvent] as $poolEvent) {
            (new ScoreSeasonEventResultJob($correction->id, $poolEvent->id))
                ->handle(app(ScoreSeasonEventResult::class));
        }
        (new FinalizeSeasonEventResultJob($correction->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertSame(PoolStatus::Active, $activePool->fresh()->status);
        $this->assertSame(PoolStatus::Completed, $completedPool->fresh()->status);
        $this->assertSame(0, PointEntry::query()->where('pool_member_id', $activeMember->id)->sum('points'));
        $this->assertSame(0, PointEntry::query()->where('pool_member_id', $completedMember->id)->sum('points'));
        $this->assertSame(0, PoolScoreProjection::query()->where('pool_member_id', $activeMember->id)->value('total_points'));
        $this->assertSame(0, PoolScoreProjection::query()->where('pool_member_id', $completedMember->id)->value('total_points'));
    }

    public function test_out_of_order_correction_jobs_reconcile_the_complete_result_chain_deterministically(): void
    {
        Bus::fake();
        [$event, $firstOption, $poolEvent] = $this->scoringContext();
        $secondOption = $event->options()->whereKeyNot($firstOption->id)->firstOrFail();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $firstResult = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$firstOption->id]);
        (new ScoreSeasonEventResultJob($firstResult->id, $poolEvent->id))
            ->handle(app(ScoreSeasonEventResult::class));
        (new FinalizeSeasonEventResultJob($firstResult->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $correctedResult = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$secondOption->id], 'Correction');

        (new ScoreSeasonEventResultJob($correctedResult->id, $poolEvent->id))
            ->handle(app(ScoreSeasonEventResult::class));
        (new ScoreSeasonEventResultJob($firstResult->id, $poolEvent->id))
            ->handle(app(ScoreSeasonEventResult::class));
        (new FinalizeSeasonEventResultJob($correctedResult->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertSame(0, PointEntry::query()->where('pool_event_id', $poolEvent->id)->sum('points'));
        $this->assertDatabaseCount('point_entries', 3);
        $this->assertSame('published', $correctedResult->fresh()->status);
    }

    public function test_failed_official_result_entries_remain_hidden_from_totals_and_projections(): void
    {
        Bus::fake();
        [$event, $option, $poolEvent, $member] = $this->scoringContext();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);
        $result = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);
        $job = new ScoreSeasonEventResultJob($result->id, $poolEvent->id);

        $job->handle(app(ScoreSeasonEventResult::class));
        $job->failed(new \RuntimeException('Simulated terminal failure.'));
        app(RebuildPoolScoreProjection::class)->handle($poolEvent->pool);
        Cache::forget(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id));

        $leaderboard = app(BuildPoolLeaderboard::class)->handle($poolEvent->pool);
        $this->assertSame('failed', $result->fresh()->status);
        $this->assertDatabaseCount('point_entries', 1);
        $this->assertSame(0, $leaderboard->firstWhere('member.id', $member->id)['total_points']);
        $this->assertSame(0, PoolScoreProjection::query()->where('pool_member_id', $member->id)->value('total_points'));
        $this->assertNull($event->fresh()->latestResult);
    }

    public function test_published_canonical_eviction_and_its_correction_rebuild_houseguest_activity(): void
    {
        Bus::fake();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create(['is_active' => true]);
        $eventType = EventType::factory()->create(['slug' => 'eviction', 'is_standard' => true]);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'event_type_id' => $eventType->id,
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $firstOption = $event->options->firstWhere('houseguest_id', $houseguests[0]->id);
        $secondOption = $event->options->firstWhere('houseguest_id', $houseguests[1]->id);
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $firstResult = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$firstOption->id]);
        (new FinalizeSeasonEventResultJob($firstResult->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertFalse($houseguests[0]->fresh()->is_active);
        $this->assertTrue($houseguests[1]->fresh()->is_active);

        $correction = app(PublishSeasonEventResult::class)->handle(
            $event->fresh(),
            $administrator,
            [$secondOption->id],
            'La production a corrigé la personne éliminée.',
        );
        (new FinalizeSeasonEventResultJob($correction->id))->handle(
            app(TransitionSeasonEvent::class),
            app(\App\Actions\Audit\RecordAuditLog::class),
            app(\App\Actions\Houseguests\RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $this->assertTrue($houseguests[0]->fresh()->is_active);
        $this->assertFalse($houseguests[1]->fresh()->is_active);
    }

    /** @return array{SeasonEvent, \App\Models\SeasonEventOption, PoolEvent, PoolMember} */
    private function scoringContext(): array
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        Houseguest::factory()->count(2)->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $option = $event->options->firstOrFail();
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
            'scoring_config' => [
                'prediction' => ['points_per_correct' => 7, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);

        return [$event, $option, $poolEvent, $member];
    }
}
