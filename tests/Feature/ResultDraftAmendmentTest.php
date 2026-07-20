<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
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
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ResultDraftAmendmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_local_manual_drafts_can_be_amended_without_visibility_or_points_and_all_audits_sort_option_ids(): void
    {
        [$event, $options, $owner, $viewer] = $this->localContext();
        $publish = app(PublishEventResult::class);

        $result = $publish->handle($event, $owner, [$options[1]->id, $options[0]->id]);
        $this->assertAuditOptionIds('event.result_recorded', [$options[0]->id, $options[1]->id]);

        $result = $publish->amendDraft($result, $owner, [$options[1]->id], 'Note interne avant publication.');

        $this->assertSame('draft', $result->status);
        $this->assertSame([$options[1]->id], $result->options->pluck('id')->all());
        $this->assertSame('Note interne avant publication.', $result->correction_reason);
        $this->assertNull($event->fresh()->latestResult);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertAuditOptionIds('event.result_draft_amended', [$options[1]->id]);
        Livewire::actingAs($viewer)
            ->test('pools.predictions', ['pool' => $event->pool])
            ->assertSee($event->name)
            ->assertDontSee('Note interne avant publication.');

        $result = $publish->publishDraft($result, $owner);
        $this->assertAuditOptionIds('event.result_published', [$options[1]->id]);

        $correction = $publish->handle(
            $event->fresh(),
            $owner,
            [$options[0]->id],
            'Première justification.',
        );
        $this->assertAuditOptionIds('event.correction_recorded', [$options[0]->id]);

        $correction = $publish->amendDraft(
            $correction,
            $owner,
            [$options[1]->id],
            'Justification corrigée.',
        );

        $this->assertSame($result->id, $event->fresh()->latestResult?->id);
        $this->assertSame(0, PointEntry::query()->count());
        $amendAudit = AuditLog::query()->where('action', 'event.result_draft_amended')->latest('id')->firstOrFail();
        $this->assertSame('Première justification.', data_get($amendAudit->metadata, 'before.correction_reason'));
        $this->assertSame('Justification corrigée.', data_get($amendAudit->metadata, 'after.correction_reason'));

        $publish->publishDraft($correction, $owner);
        $this->assertAuditOptionIds('event.result_corrected', [$options[1]->id]);
    }

    public function test_official_manual_drafts_can_be_amended_without_visibility_or_points_and_all_audits_sort_option_ids(): void
    {
        Bus::fake();
        [$event, $options, $poolEvent, $administrator, $viewer] = $this->officialContext();
        $publish = app(PublishSeasonEventResult::class);

        $result = $publish->handle($event, $administrator, [$options[1]->id, $options[0]->id]);
        $this->assertAuditOptionIds('season_event.result_recorded', [$options[0]->id, $options[1]->id]);

        $result = $publish->amendDraft($result, $administrator, [$options[1]->id], 'Note officielle confidentielle.');

        $this->assertSame('draft', $result->status);
        $this->assertNull($event->fresh()->latestResult);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertAuditOptionIds('season_event.result_draft_amended', [$options[1]->id]);
        Livewire::actingAs($viewer)
            ->test('pools.predictions', ['pool' => $poolEvent->pool])
            ->assertSee($event->name)
            ->assertDontSee('Note officielle confidentielle.');

        $result = $publish->publishDraft($result, $administrator);
        $this->assertAuditOptionIds('season_event.result_scoring_started', [$options[1]->id]);
        $this->completeOfficialPublication($result, $poolEvent);
        $this->assertAuditOptionIds('season_event.result_published', [$options[1]->id]);

        Bus::fake();
        $correction = $publish->handle(
            $event->fresh(),
            $administrator,
            [$options[0]->id],
            'Première justification officielle.',
        );
        $this->assertAuditOptionIds('season_event.correction_recorded', [$options[0]->id]);

        $correction = $publish->amendDraft(
            $correction,
            $administrator,
            [$options[1]->id],
            'Justification officielle corrigée.',
        );
        $this->assertSame($result->id, $event->fresh()->latestResult?->id);
        $this->assertSame(0, PointEntry::query()->count());

        $correction = $publish->publishDraft($correction, $administrator);
        $this->completeOfficialPublication($correction, $poolEvent);
        $this->assertAuditOptionIds('season_event.result_corrected', [$options[1]->id]);
    }

    public function test_draft_amendment_actions_enforce_their_policies(): void
    {
        [$event, $options, $owner] = $this->localContext();
        $result = app(PublishEventResult::class)->handle($event, $owner, [$options[0]->id]);

        $this->expectException(AuthorizationException::class);

        app(PublishEventResult::class)->amendDraft($result, User::factory()->create(), [$options[1]->id]);
    }

    public function test_query_builder_inserts_reject_inconsistent_result_status_and_publication_dates(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $seasonEvent = SeasonEvent::factory()->create();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        $this->assertQueryRejected(fn () => DB::table('event_results')->insert([
            'event_id' => $event->id,
            'created_by' => $user->id,
            'version' => 1,
            'status' => 'published',
            'published_at' => null,
            ...$timestamps,
        ]));
        $this->assertQueryRejected(fn () => DB::table('event_results')->insert([
            'event_id' => $event->id,
            'created_by' => $user->id,
            'version' => 1,
            'status' => 'draft',
            'published_at' => now(),
            ...$timestamps,
        ]));
        $this->assertQueryRejected(fn () => DB::table('season_event_results')->insert([
            'season_event_id' => $seasonEvent->id,
            'created_by' => $user->id,
            'version' => 1,
            'status' => 'published',
            'published_at' => null,
            ...$timestamps,
        ]));
        $this->assertQueryRejected(fn () => DB::table('season_event_results')->insert([
            'season_event_id' => $seasonEvent->id,
            'created_by' => $user->id,
            'version' => 1,
            'status' => 'pending',
            'published_at' => now(),
            ...$timestamps,
        ]));
    }

    /** @return array{Event, list<EventOption>, User, User} */
    private function localContext(): array
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);
        PoolMember::factory()->for($pool)->for($viewer)->create(['draft_position' => 2]);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'mode' => EventMode::Prediction,
            'status' => EventStatus::Draft,
            'result_min_selections' => 1,
            'result_max_selections' => 2,
            'result_publication_mode' => ResultPublicationMode::Manual,
        ]);
        $options = [
            EventOption::factory()->for($event)->create(['position' => 1]),
            EventOption::factory()->for($event)->create(['position' => 2]),
        ];
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDays(2),
            'locks_at' => now()->subDay(),
        ]);

        return [$event, $options, $owner, $viewer];
    }

    /** @return array{SeasonEvent, list<SeasonEventOption>, PoolEvent, User, User} */
    private function officialContext(): array
    {
        $administrator = User::factory()->admin()->create();
        $viewer = User::factory()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'status' => EventStatus::Draft,
            'result_min_selections' => 1,
            'result_max_selections' => 2,
            'result_publication_mode' => ResultPublicationMode::Manual,
        ]);
        $options = [
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 1]),
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 2]),
        ];
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDays(2),
            'locks_at' => now()->subDay(),
        ]);
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $viewer->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($viewer)->create(['role' => PoolMemberRole::Owner]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
        ]);

        return [$event, $options, $poolEvent, $administrator, $viewer];
    }

    private function completeOfficialPublication(SeasonEventResult $result, PoolEvent $poolEvent): void
    {
        (new ScoreSeasonEventResultJob($result->id, $poolEvent->id))->handle(app(ScoreSeasonEventResult::class));
        (new FinalizeSeasonEventResultJob($result->id))->handle(
            app(\App\Actions\Events\TransitionSeasonEvent::class),
            app(RecordAuditLog::class),
            app(RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );
    }

    /** @param list<int> $optionIds */
    private function assertAuditOptionIds(string $action, array $optionIds): void
    {
        $audit = AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();

        $this->assertSame($optionIds, data_get($audit->metadata, 'option_ids'));
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database must reject an inconsistent result publication status and date.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
