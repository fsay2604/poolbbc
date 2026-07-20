<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolStatus;
use App\Enums\ResultPublicationMode;
use App\Jobs\FinalizeSeasonEventResultJob;
use App\Jobs\ScoreSeasonEventResultJob;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class OfficialResultPublicationRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_finalizer_refuses_to_publish_until_every_active_pool_has_a_completed_receipt(): void
    {
        [$event, $option, $poolEvents, $administrator] = $this->publicationContext(poolCount: 2);
        Bus::fake();

        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);

        $this->assertSame('pending', $result->status);
        $this->assertCount(2, $result->scoringReceipts);
        $this->assertTrue($result->scoringReceipts->every(fn ($receipt): bool => $receipt->completed_at === null));

        $this->score($result, $poolEvents[0]);
        $this->finalize($result);

        $this->assertSame('pending', $result->fresh()->status);
        $this->assertNull($result->fresh()->published_at);
        $this->assertNotNull($result->scoringReceipts()->where('pool_event_id', $poolEvents[0]->id)->value('completed_at'));
        $this->assertNull($result->scoringReceipts()->where('pool_event_id', $poolEvents[1]->id)->value('completed_at'));

        $this->score($result, $poolEvents[1]);
        $this->finalize($result);

        $this->assertSame('published', $result->fresh()->status);
        $this->assertNotNull($result->fresh()->published_at);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
    }

    public function test_soft_deleted_creator_can_still_be_used_for_the_publication_audit(): void
    {
        [$event, $option, $poolEvents, $administrator] = $this->publicationContext();
        Bus::fake();
        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);
        $this->score($result, $poolEvents[0]);

        $administrator->delete();
        $this->assertSoftDeleted($administrator);
        $this->assertTrue($result->fresh()->creator->is($administrator));

        $this->finalize($result);

        $this->assertSame('published', $result->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => 'season_event.result_published',
            'auditable_id' => $result->id,
        ]);
    }

    public function test_pending_publication_can_recreate_a_lost_dispatch_without_duplicating_receipts(): void
    {
        [$event, $option, $poolEvents, $administrator] = $this->publicationContext(poolCount: 2);
        Bus::fake();
        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);

        Bus::fake();
        $retried = app(PublishSeasonEventResult::class)->retry($result, $administrator);

        $this->assertSame('pending', $retried->status);
        $this->assertDatabaseCount('season_event_result_scoring_receipts', 2);
        Bus::assertChained([
            new ScoreSeasonEventResultJob($result->id, $poolEvents[0]->id),
            new ScoreSeasonEventResultJob($result->id, $poolEvents[1]->id),
            new FinalizeSeasonEventResultJob($result->id),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => 'season_event.result_scoring_retried',
            'auditable_id' => $result->id,
        ]);

        app(PublishSeasonEventResult::class)->retry($result->fresh(), $administrator);
        $this->assertDatabaseCount('season_event_result_scoring_receipts', 2);
    }

    public function test_failed_retry_queues_only_incomplete_receipts_and_then_can_queue_only_the_finalizer(): void
    {
        [$event, $option, $poolEvents, $administrator] = $this->publicationContext(poolCount: 2);
        Bus::fake();
        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);
        $this->score($result, $poolEvents[0]);
        (new ScoreSeasonEventResultJob($result->id, $poolEvents[1]->id))
            ->failed(new RuntimeException('Simulated terminal scoring failure.'));

        $this->assertSame('failed', $result->fresh()->status);

        Bus::fake();
        app(PublishSeasonEventResult::class)->retry($result->fresh(), $administrator);

        $this->assertSame('pending', $result->fresh()->status);
        Bus::assertChained([
            new ScoreSeasonEventResultJob($result->id, $poolEvents[1]->id),
            new FinalizeSeasonEventResultJob($result->id),
        ]);

        $this->score($result, $poolEvents[1]);
        Bus::fake();
        app(PublishSeasonEventResult::class)->retry($result->fresh(), $administrator);

        Bus::assertChained([
            new FinalizeSeasonEventResultJob($result->id),
        ]);
        $this->finalize($result);
        $this->assertSame('published', $result->fresh()->status);
    }

    public function test_official_admin_screen_exposes_the_recovery_action_for_failed_publication(): void
    {
        [$event, $option, , $administrator] = $this->publicationContext();
        Bus::fake();
        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);
        (new ScoreSeasonEventResultJob($result->id, $event->poolEvents()->firstOrFail()->id))
            ->failed(new RuntimeException('Simulated terminal scoring failure.'));

        Livewire::actingAs($administrator)
            ->test('admin.official-results')
            ->assertSee('Reprendre le pointage')
            ->call('retryPublication', $event->id)
            ->assertDispatched('official-result-retried');

        $this->assertSame('pending', $result->fresh()->status);
    }

    /** @return array{SeasonEvent, SeasonEventOption, list<PoolEvent>, User} */
    private function publicationContext(int $poolCount = 1): array
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'status' => EventStatus::Draft,
            'result_publication_mode' => ResultPublicationMode::Immediate,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
        ]);
        $option = SeasonEventOption::factory()->for($event, 'event')->create(['position' => 1]);
        $event->update(['status' => EventStatus::Locked]);
        $poolEvents = [];

        foreach (range(1, $poolCount) as $position) {
            $owner = User::factory()->create();
            $pool = Pool::factory()->predictionOnly()->create([
                'season_id' => $season->id,
                'owner_id' => $owner->id,
                'status' => PoolStatus::Active,
                'name' => "Pool {$position}",
            ]);
            PoolMember::factory()->for($pool)->for($owner)->create([
                'role' => PoolMemberRole::Owner,
            ]);
            $poolEvents[] = PoolEvent::factory()->create([
                'pool_id' => $pool->id,
                'season_event_id' => $event->id,
                'mode' => EventMode::Prediction,
            ]);
        }

        return [$event, $option, $poolEvents, $administrator];
    }

    private function score(SeasonEventResult $result, PoolEvent $poolEvent): void
    {
        (new ScoreSeasonEventResultJob($result->id, $poolEvent->id))
            ->handle(app(ScoreSeasonEventResult::class));
    }

    private function finalize(SeasonEventResult $result): void
    {
        (new FinalizeSeasonEventResultJob($result->id))->handle(
            app(TransitionSeasonEvent::class),
            app(RecordAuditLog::class),
            app(RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );
    }
}
