<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Actions\Scoring\PublishEventResult;
use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolStatus;
use App\Enums\ResultPublicationMode;
use App\Jobs\FinalizeSeasonEventResultJob;
use App\Jobs\ScoreSeasonEventResultJob;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\EventPrediction;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ResultPublicationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_local_manual_result_stays_private_and_unscored_until_explicit_publication(): void
    {
        [$event, $options, $owner, $member] = $this->localContext();

        $result = app(PublishEventResult::class)->handle($event, $owner, [$options[0]->id]);

        $this->assertSame('draft', $result->status);
        $this->assertNull($result->published_at);
        $this->assertSame(EventStatus::ResultEntered, $event->fresh()->status);
        $this->assertNull($event->fresh()->latestResult);
        $this->assertSame($result->id, $event->fresh()->draftResult?->id);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.result_recorded',
            'auditable_id' => $result->id,
        ]);

        $memberComponent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $event->pool])
            ->assertSee($event->name)
            ->assertSee('En attente de publication');
        $memberEvent = $memberComponent->get('predictionEvents')->firstWhere('key', "local-{$event->id}");
        $this->assertNotNull($memberEvent);
        $this->assertFalse($memberEvent->hasPublishedResult);
        $this->assertSame([], $memberEvent->resultOptionLabels);

        $published = app(PublishEventResult::class)->publishDraft($result, $owner);

        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame($published->id, $event->fresh()->latestResult?->id);
        $this->assertSame(2, (int) $member->pointEntries()->published()->sum('points'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.result_published',
            'auditable_id' => $result->id,
        ]);

        $publishedEvent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $event->pool])
            ->assertSee($options[0]->label)
            ->get('predictionEvents')
            ->firstWhere('key', "local-{$event->id}");
        $this->assertNotNull($publishedEvent);
        $this->assertTrue($publishedEvent->hasPublishedResult);
        $this->assertSame([$options[0]->label], $publishedEvent->resultOptionLabels);
    }

    public function test_local_manual_correction_keeps_previous_public_points_until_the_new_version_is_published(): void
    {
        [$event, $options, $owner, $member] = $this->localContext();
        $first = app(PublishEventResult::class)->handle($event, $owner, [$options[0]->id]);
        $first = app(PublishEventResult::class)->publishDraft($first, $owner);

        $correctionReason = 'Correction confidentielle en attente de publication.';
        $correction = app(PublishEventResult::class)->handle(
            $event->fresh(),
            $owner,
            [$options[1]->id],
            $correctionReason,
        );

        $this->assertSame('draft', $correction->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame($first->id, $event->fresh()->latestResult?->id);
        $this->assertSame(2, (int) $member->pointEntries()->published()->sum('points'));
        $this->assertSame(1, PointEntry::query()->published()->count());

        $memberComponent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $event->pool])
            ->assertDontSee($correctionReason)
            ->call('$refresh')
            ->assertDontSee($correctionReason);
        $memberEvent = $memberComponent->get('predictionEvents')->firstWhere('key', "local-{$event->id}");
        $this->assertNotNull($memberEvent);
        $this->assertTrue($memberEvent->hasPublishedResult);
        $this->assertSame([$options[0]->label], $memberEvent->resultOptionLabels);

        Livewire::actingAs($owner)
            ->test('pools.results', ['pool' => $event->pool])
            ->assertSee($correctionReason)
            ->call('$refresh')
            ->assertSee($correctionReason);

        $publishedCorrection = app(PublishEventResult::class)->publishDraft($correction, $owner);

        $this->assertSame('published', $publishedCorrection->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame($publishedCorrection->id, $event->fresh()->latestResult?->id);
        $this->assertSame(0, (int) $member->pointEntries()->published()->sum('points'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.result_corrected',
            'auditable_id' => $correction->id,
        ]);

        $correctedEvent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $event->pool])
            ->get('predictionEvents')
            ->firstWhere('key', "local-{$event->id}");
        $this->assertNotNull($correctedEvent);
        $this->assertSame([$options[1]->label], $correctedEvent->resultOptionLabels);
    }

    public function test_official_manual_result_is_queued_only_after_explicit_publication_and_corrections_keep_the_old_result_visible(): void
    {
        Bus::fake();
        [$event, $options, $poolEvent, $administrator] = $this->officialContext();

        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$options[0]->id]);

        $this->assertSame('draft', $result->status);
        $this->assertSame(EventStatus::ResultEntered, $event->fresh()->status);
        $this->assertNull($event->fresh()->latestResult);
        Bus::assertNothingDispatched();

        $result = app(PublishSeasonEventResult::class)->publishDraft($result, $administrator);
        $this->assertSame('pending', $result->status);
        Bus::assertChained([
            new ScoreSeasonEventResultJob($result->id, $poolEvent->id),
            new FinalizeSeasonEventResultJob($result->id),
        ]);

        $this->completeOfficialPublication($result->id, $poolEvent->id);
        $this->assertSame('published', $result->fresh()->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);

        Bus::fake();
        $correction = app(PublishSeasonEventResult::class)->handle(
            $event->fresh(),
            $administrator,
            [$options[1]->id],
            'Correction confirmée par la production.',
        );

        $this->assertSame('draft', $correction->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame($result->id, $event->fresh()->latestResult?->id);
        Bus::assertNothingDispatched();

        $correction = app(PublishSeasonEventResult::class)->publishDraft($correction, $administrator);
        $this->completeOfficialPublication($correction->id, $poolEvent->id);

        $this->assertSame('published', $correction->fresh()->status);
        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        $this->assertSame($correction->id, $event->fresh()->latestResult?->id);
    }

    public function test_non_administrator_cannot_record_an_official_result(): void
    {
        [$event, $options] = $this->officialContext();

        $this->expectException(AuthorizationException::class);

        app(PublishSeasonEventResult::class)->handle($event, User::factory()->create(), [$options[0]->id]);
    }

    public function test_leaderboard_never_hydrates_points_from_a_pending_official_result(): void
    {
        Bus::fake();
        [$event, $options, $poolEvent, $administrator] = $this->officialContext();
        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$options[0]->id]);
        $result = app(PublishSeasonEventResult::class)->publishDraft($result, $administrator);
        $member = $poolEvent->pool->members()->where('user_id', $poolEvent->pool->owner_id)->firstOrFail();
        $privateReason = 'Pointage interne en attente de finalisation.';

        PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'pool_event_id' => $poolEvent->id,
            'season_event_result_id' => $result->id,
            'type' => 'prediction',
            'points' => 37,
            'reason' => $privateReason,
            'idempotency_key' => "pending-result:{$result->id}:member:{$member->id}",
        ]);
        Cache::forget(BuildPoolLeaderboard::cacheKey($poolEvent->pool_id));

        Livewire::actingAs($member->user)
            ->test('pools.leaderboard', ['pool' => $poolEvent->pool])
            ->assertDontSee($privateReason)
            ->call('$refresh')
            ->assertDontSee($privateReason);
    }

    public function test_database_rejects_bulk_pool_event_rule_changes_after_the_official_event_is_locked(): void
    {
        [, , $poolEvent] = $this->officialContext();

        $this->expectException(QueryException::class);

        PoolEvent::query()->whereKey($poolEvent->id)->update([
            'scoring_config' => json_encode([
                'owner' => ['points_per_match' => 999],
                'prediction' => ['points_per_correct' => 999],
                'allow_negative' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array{Event, list<EventOption>, User, PoolMember} */
    private function localContext(): array
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);
        $member = PoolMember::factory()->for($pool)->for($memberUser)->create(['draft_position' => 2]);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'mode' => EventMode::Prediction,
            'status' => EventStatus::Draft,
            'result_publication_mode' => ResultPublicationMode::Manual,
            'scoring_config' => [
                'owner' => ['points_per_match' => 0],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        $options = [
            EventOption::factory()->for($event)->create(['position' => 1]),
            EventOption::factory()->for($event)->create(['position' => 2]),
        ];
        $prediction = EventPrediction::query()->create([
            'event_id' => $event->id,
            'pool_member_id' => $member->id,
            'status' => 'locked',
            'submitted_at' => now(),
            'locked_at' => now(),
        ]);
        $prediction->options()->sync([$options[0]->id]);
        $event->update(['status' => EventStatus::Locked]);

        return [$event, $options, $owner, $member];
    }

    /** @return array{SeasonEvent, list<SeasonEventOption>, PoolEvent, User} */
    private function officialContext(): array
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'status' => EventStatus::Draft,
            'result_publication_mode' => ResultPublicationMode::Manual,
        ]);
        $options = [
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 1]),
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 2]),
        ];
        $owner = User::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);
        $poolEvent = PoolEvent::factory()->create([
            'season_event_id' => $event->id,
            'pool_id' => $pool->id,
            'mode' => EventMode::Prediction,
        ]);
        $event->update(['status' => EventStatus::Locked]);

        return [$event, $options, $poolEvent, $administrator];
    }

    private function completeOfficialPublication(int $resultId, int $poolEventId): void
    {
        (new ScoreSeasonEventResultJob($resultId, $poolEventId))
            ->handle(app(ScoreSeasonEventResult::class));
        (new FinalizeSeasonEventResultJob($resultId))->handle(
            app(\App\Actions\Events\TransitionSeasonEvent::class),
            app(RecordAuditLog::class),
            app(RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );
    }
}
