<?php

namespace Tests\Feature;

use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\EventType;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CanonicalEventDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_one_official_result_scores_multiple_pools_with_their_own_rules_idempotently(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $option] = $this->openHouseguestEvent();
        $administrator = User::factory()->admin()->create();

        [$firstPoolEvent, $firstMember] = $this->predictionPoolEvent($event, 2);
        [$secondPoolEvent, $secondMember] = $this->predictionPoolEvent($event, 5);

        app(SubmitPoolEventPrediction::class)->handle($firstPoolEvent, $firstMember, [$option->id]);
        app(SubmitPoolEventPrediction::class)->handle($secondPoolEvent, $secondMember, [$option->id]);

        Carbon::setTestNow('2026-07-16 13:00:00');
        $result = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);

        $this->assertDatabaseCount('season_event_results', 1);
        $this->assertDatabaseHas('point_entries', [
            'pool_member_id' => $firstMember->id,
            'pool_event_id' => $firstPoolEvent->id,
            'season_event_result_id' => $result->id,
            'points' => 2,
        ]);
        $this->assertDatabaseHas('point_entries', [
            'pool_member_id' => $secondMember->id,
            'pool_event_id' => $secondPoolEvent->id,
            'season_event_result_id' => $result->id,
            'points' => 5,
        ]);

        app(ScoreSeasonEventResult::class)->handle($result->fresh(['event', 'options']));

        $this->assertDatabaseCount('point_entries', 2);
    }

    public function test_official_result_correction_is_versioned_reverses_previous_points_and_requires_a_reason(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $option] = $this->openHouseguestEvent();
        $administrator = User::factory()->admin()->create();
        [$poolEvent, $member] = $this->predictionPoolEvent($event, 3);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);

        Carbon::setTestNow('2026-07-16 13:00:00');
        $first = app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);
        $reconciliation = PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'pool_event_id' => $poolEvent->id,
            'type' => 'round_reconciliation',
            'points' => 4,
            'reason' => 'Canonical round score reconciliation.',
            'idempotency_key' => 'round-reconciliation:test-correction-lifecycle',
        ]);

        $this->assertSame(7, PointEntry::query()->where('pool_member_id', $member->id)->sum('points'));

        try {
            app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);
            $this->fail('A correction without a reason should have failed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_reason', $exception->errors());
        }

        $second = app(PublishSeasonEventResult::class)->handle(
            $event->fresh(),
            $administrator,
            [$option->id],
            'Correction confirmée par la production.',
        );

        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertDatabaseHas('point_entries', [
            'season_event_result_id' => $second->id,
            'reverses_point_entry_id' => PointEntry::query()->where('season_event_result_id', $first->id)->value('id'),
            'points' => -3,
        ]);
        $this->assertDatabaseHas('point_entries', [
            'season_event_result_id' => $second->id,
            'reverses_point_entry_id' => $reconciliation->id,
            'points' => -4,
            'idempotency_key' => "round-reconciliation:{$reconciliation->id}:correction-reversal",
        ]);
        $this->assertSame(3, PointEntry::query()->where('pool_member_id', $member->id)->sum('points'));
    }

    public function test_only_system_administrators_can_publish_official_results(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $option] = $this->openHouseguestEvent();
        Carbon::setTestNow('2026-07-16 13:00:00');

        $this->expectException(AuthorizationException::class);

        app(PublishSeasonEventResult::class)->handle($event->fresh(), User::factory()->create(), [$option->id]);
    }

    public function test_official_options_and_pool_rules_freeze_after_the_first_response(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $option] = $this->openHouseguestEvent();
        [$poolEvent, $member] = $this->predictionPoolEvent($event, 2);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id], false);

        $this->assertCount(1, $event->fresh()->options);
        app(TransitionSeasonEvent::class)->synchronize($event->fresh());
        $this->assertCount(1, $event->fresh()->options);

        try {
            $event->update(['locks_at' => now()->addHours(2)]);
            $this->fail('Official rules should be frozen after opening.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $poolEvent->update(['scoring_config' => ['prediction' => ['points_per_correct' => 99]]]);
    }

    public function test_official_option_snapshot_can_include_inactive_houseguests_and_explicit_none(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $active = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $inactive = Houseguest::factory()->for($season)->create(['is_active' => false]);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'answer_source' => AnswerSource::Houseguests,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
            'include_inactive_houseguests' => true,
            'allow_none' => true,
        ]);

        $event = app(TransitionSeasonEvent::class)->transition($event, EventStatus::Open, User::factory()->admin()->create());

        $this->assertEqualsCanonicalizing(
            [$active->id, $inactive->id],
            $event->options->pluck('houseguest_id')->filter()->all(),
        );
        $this->assertTrue($event->options->contains(fn ($option): bool => $option->is_none && $option->value === 'none'));
        $this->assertNotNull($event->options_locked_at);
    }

    public function test_pool_event_rejects_an_official_event_from_another_season(): void
    {
        $event = SeasonEvent::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create();

        $this->expectException(ValidationException::class);

        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
        ]);
    }

    public function test_database_enforces_global_event_type_and_pool_event_source_invariants(): void
    {
        $eventType = EventType::factory()->create(['pool_id' => null, 'slug' => 'globally-unique']);

        try {
            DB::table('event_types')->insert([
                'pool_id' => null,
                'scope_key' => 'global',
                'name' => 'Duplicate',
                'slug' => $eventType->slug,
                'is_standard' => true,
                'default_mode' => EventMode::Prediction->value,
                'answer_source' => AnswerSource::Houseguests->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Global event type slugs must be unique in the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('event_types', 1);
        }

        $pool = Pool::factory()->predictionOnly()->create();
        try {
            DB::table('pool_events')->insert([
                'pool_id' => $pool->id,
                'season_event_id' => null,
                'local_event_id' => null,
                'mode' => EventMode::Prediction->value,
                'is_active' => true,
                'visibility' => 'after_lock',
                'prediction_min_selections' => 1,
                'prediction_max_selections' => 1,
                'scoring_config' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A pool event must reference exactly one source in the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('pool_events', 0);
        }
    }

    public function test_database_rejects_forged_event_type_scope_keys_from_the_query_builder(): void
    {
        $eventType = EventType::factory()->create([
            'pool_id' => null,
            'slug' => 'forged-scope',
        ]);

        try {
            DB::table('event_types')->insert([
                'pool_id' => null,
                'scope_key' => 'pool:999',
                'name' => 'Forged global duplicate',
                'slug' => $eventType->slug,
                'is_standard' => false,
                'default_mode' => EventMode::Prediction->value,
                'answer_source' => AnswerSource::Houseguests->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A forged scope key must not bypass global slug uniqueness.');
        } catch (QueryException) {
            $this->assertDatabaseCount('event_types', 1);
        }

        $pool = Pool::factory()->predictionOnly()->create();
        $localEventType = EventType::factory()->for($pool)->create();

        try {
            DB::table('event_types')
                ->where('id', $localEventType->id)
                ->update(['scope_key' => 'global']);
            $this->fail('A query builder update must not detach a scope key from its pool.');
        } catch (QueryException) {
            $this->assertSame('pool:'.$pool->id, $localEventType->fresh()->scope_key);
        }
    }

    public function test_open_official_metadata_is_immutable_and_pool_scoring_rules_freeze_at_lock(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $option] = $this->openHouseguestEvent();

        try {
            $event->update(['name' => 'Changed after opening']);
            $this->fail('Official metadata should be frozen after opening.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        try {
            $event->fresh()->update(['options_locked_at' => null]);
            $this->fail('The official option snapshot should not be unlockable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        $draftEvent = SeasonEvent::factory()->for($event->round, 'round')->create([
            'status' => EventStatus::Draft,
            'options_locked_at' => null,
        ]);
        try {
            $option->update(['season_event_id' => $draftEvent->id]);
            $this->fail('A frozen official option should not be movable to another event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('option', $exception->errors());
        }

        $pool = Pool::factory()->rosterOnly()->create(['season_id' => $event->round->season_id]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Roster,
        ]);

        $poolEvent->update(['scoring_config' => ['owner' => ['points_per_match' => 99]]]);
        $this->assertSame(99, data_get($poolEvent->fresh()->scoring_config, 'owner.points_per_match'));

        app(TransitionSeasonEvent::class)->transition($event->fresh(), EventStatus::Locked, User::factory()->admin()->create());

        $this->expectException(ValidationException::class);
        $poolEvent->fresh()->update(['scoring_config' => ['owner' => ['points_per_match' => 100]]]);
    }

    public function test_incomplete_canonical_drafts_are_allowed_and_submitted_answers_lock_at_the_exact_deadline(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'answer_source' => AnswerSource::Boolean,
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addMinute(),
            'result_min_selections' => 1,
            'result_max_selections' => 1,
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        [$poolEvent, $member] = $this->predictionPoolEvent($event, 2);
        $option = $event->options->firstOrFail();

        $draft = app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [], false);
        $this->assertSame(PredictionStatus::Draft, $draft->status);

        try {
            app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [], true);
            $this->fail('An incomplete submission should have failed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $submitted = app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);
        $this->assertSame(PredictionStatus::Submitted, $submitted->status);

        Carbon::setTestNow('2026-07-16 12:01:00');

        try {
            app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);
            $this->fail('The exact deadline should close submissions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $this->assertSame(PredictionStatus::Locked, $submitted->fresh()->status);
    }

    /** @return array{SeasonEvent, \App\Models\SeasonEventOption} */
    private function openHouseguestEvent(): array
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        Houseguest::factory()->for($season)->create(['name' => 'Alex', 'sort_order' => 1]);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);

        return [$event, $event->options->firstOrFail()];
    }

    /** @return array{PoolEvent, PoolMember} */
    private function predictionPoolEvent(SeasonEvent $event, int $pointsPerCorrect): array
    {
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $event->round->season_id,
        ]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
            'scoring_config' => [
                'prediction' => [
                    'points_per_correct' => $pointsPerCorrect,
                    'exact_match_bonus' => 0,
                    'wrong_answer_penalty' => 0,
                ],
                'allow_negative' => false,
            ],
        ]);

        return [$poolEvent, $member];
    }
}
