<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Pools\ActivatePool;
use App\Actions\Pools\JoinPool;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\DraftMode;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Enums\PoolCompetitionMode;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Enums\PredictionStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\EventPrediction;
use App\Models\Houseguest;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class EventLifecycleAndPoolModeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prediction_only_pool_has_no_draft_and_activates_directly(): void
    {
        $season = Season::factory()->create();
        $owner = User::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, $this->poolData($season, PoolCompetitionMode::PredictionOnly));

        $this->assertNull($pool->draft);
        $this->assertNull($pool->memberFor($owner)?->draft_position);

        $member = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $this->assertNull($member->draft_position);

        $pool = app(ActivatePool::class)->handle($pool, $owner);
        $this->assertSame(PoolStatus::Active, $pool->status);
    }

    public function test_pool_mode_rejects_an_incompatible_event_mode(): void
    {
        $season = Season::factory()->create();
        $owner = User::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, $this->poolData($season, PoolCompetitionMode::PredictionOnly));
        $round = Round::factory()->for($pool)->create();

        $this->expectException(ValidationException::class);
        app(CreateEvent::class)->handle($pool, $owner, [
            'round_id' => $round->id,
            'name' => 'Roster event',
            'mode' => 'roster',
            'answer_source' => 'boolean',
            'locks_at' => now()->addHour(),
        ]);
    }

    public function test_pools_and_new_official_rounds_are_linked_in_both_creation_orders(): void
    {
        $season = Season::factory()->create();
        $existingRound = SeasonRound::factory()->for($season)->create();
        $existingEvent = SeasonEvent::factory()->for($existingRound, 'round')->create();
        $owner = User::factory()->create();

        $pool = $this->createPoolOpenForRegistration($owner, $this->poolData($season, PoolCompetitionMode::PredictionOnly));
        $this->assertDatabaseHas('pool_events', [
            'pool_id' => $pool->id,
            'season_event_id' => $existingEvent->id,
            'mode' => 'prediction',
        ]);
        $completedPool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'status' => PoolStatus::Completed,
        ]);

        $newRound = app(CreateSeasonRoundFromTemplate::class)->handle(
            $season,
            User::factory()->admin()->create(),
            OfficialRoundTemplate::NoVeto,
            'Semaine suivante',
        );

        $this->assertSame(
            $newRound->events()->count(),
            $pool->poolEvents()->whereIn('season_event_id', $newRound->events()->pluck('id'))->count(),
        );
        $this->assertSame(
            0,
            $completedPool->poolEvents()->whereIn('season_event_id', $newRound->events()->pluck('id'))->count(),
        );
    }

    public function test_incomplete_draft_is_allowed_but_incomplete_submission_is_rejected(): void
    {
        [$event, $member] = $this->openEventWithMember(minimum: 2);

        $prediction = app(SubmitEventPrediction::class)->handle($event, $member, [], false);
        $this->assertSame(PredictionStatus::Draft, $prediction->status);

        $this->expectException(ValidationException::class);
        app(SubmitEventPrediction::class)->handle($event, $member, [], true);
    }

    public function test_incomplete_local_autosave_demotes_a_submitted_prediction_to_draft(): void
    {
        [$event, $member, $option] = $this->openEventWithMember();
        $prediction = app(SubmitEventPrediction::class)->handle($event, $member, [$option->id], true);
        $this->assertSame(PredictionStatus::Submitted, $prediction->status);

        $prediction = app(SubmitEventPrediction::class)->handle($event, $member, [], false);

        $this->assertSame(PredictionStatus::Draft, $prediction->status);
        $this->assertNull($prediction->submitted_at);
        $this->assertCount(0, $prediction->options);
    }

    public function test_incomplete_official_autosave_demotes_a_submitted_prediction_to_draft(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->subMinute(),
            'locks_at' => now()->addHour(),
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
        ]);
        $option = SeasonEventOption::factory()->for($event, 'event')->create();
        $event->update(['status' => EventStatus::Open]);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
        ]);
        $prediction = app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id], true);
        $this->assertSame(PredictionStatus::Submitted, $prediction->status);

        $prediction = app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [], false);

        $this->assertSame(PredictionStatus::Draft, $prediction->status);
        $this->assertNull($prediction->submitted_at);
        $this->assertCount(0, $prediction->options);
    }

    public function test_local_event_page_never_loads_official_prediction_content(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => now(),
        ]);
        $option = SeasonEventOption::factory()->for($event, 'event')->create();
        SeasonEvent::query()->whereKey($event->id)->update([
            'status' => EventStatus::Locked->value,
            'options_locked_at' => now(),
        ]);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $user = User::factory()->create();
        $member = PoolMember::factory()->for($pool)->for($user)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'visibility' => 'after_publish',
        ]);
        $prediction = PoolEventPrediction::factory()->create([
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Locked,
            'submitted_at' => now()->subMinute(),
            'locked_at' => now(),
        ]);
        $prediction->options()->sync([$option->id]);

        $lockedComponent = Livewire::actingAs($user)->test('pools.events', ['pool' => $pool]);
        $lockedPoolEvent = $lockedComponent->get('officialRounds')->first()->events->first()->poolEvents->first();
        $this->assertFalse($lockedPoolEvent->relationLoaded('predictions'));

        SeasonEvent::query()->whereKey($event->id)->update(['status' => EventStatus::Published->value]);
        $publishedComponent = Livewire::actingAs($user)->test('pools.events', ['pool' => $pool]);
        $publishedPoolEvent = $publishedComponent->get('officialRounds')->first()->events->first()->poolEvents->first();
        $this->assertFalse($publishedPoolEvent->relationLoaded('predictions'));
    }

    public function test_official_prediction_visibility_cannot_be_bypassed_by_falsifying_manager_state(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => now(),
        ]);
        $secretOption = SeasonEventOption::factory()->for($event, 'event')->create([
            'label' => 'REPONSE-OFFICIELLE-SECRETE',
        ]);
        SeasonEvent::query()->whereKey($event->id)->update([
            'status' => EventStatus::Locked->value,
            'options_locked_at' => now(),
        ]);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $viewer = User::factory()->create();
        PoolMember::factory()->for($pool)->for($viewer)->create();
        $predictor = User::factory()->create(['name' => 'PARTICIPANT-OFFICIEL-SECRET']);
        $predictorMember = PoolMember::factory()->for($pool)->for($predictor)->create([
            'draft_position' => 2,
        ]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'visibility' => 'after_publish',
        ]);
        $prediction = PoolEventPrediction::factory()->create([
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $predictorMember->id,
            'status' => PredictionStatus::Locked,
            'submitted_at' => now()->subMinute(),
            'locked_at' => now(),
        ]);
        $prediction->options()->sync([$secretOption->id]);

        $component = Livewire::actingAs($viewer)
            ->test('pools.events', ['pool' => $pool])
            ->assertDontSee('PARTICIPANT-OFFICIEL-SECRET')
            ->assertDontSee('REPONSE-OFFICIELLE-SECRETE');

        $this->expectException(PublicPropertyNotFoundException::class);

        $component->set('canManage', true);
    }

    public function test_official_rounds_remain_scoped_to_the_viewed_pool_after_a_live_model_rerender(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => now(),
        ]);
        $foreignOption = SeasonEventOption::factory()->for($event, 'event')->create([
            'label' => 'REPONSE-AUTRE-POOL',
        ]);
        SeasonEvent::query()->whereKey($event->id)->update([
            'status' => EventStatus::Locked->value,
            'options_locked_at' => now(),
        ]);

        $foreignPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $foreignUser = User::factory()->create(['name' => 'MEMBRE-AUTRE-POOL']);
        $foreignMember = PoolMember::factory()->for($foreignPool)->for($foreignUser)->create();
        $foreignPoolEvent = PoolEvent::factory()->create([
            'pool_id' => $foreignPool->id,
            'season_event_id' => $event->id,
        ]);
        $foreignPrediction = PoolEventPrediction::factory()->create([
            'pool_event_id' => $foreignPoolEvent->id,
            'pool_member_id' => $foreignMember->id,
            'status' => PredictionStatus::Locked,
            'submitted_at' => now()->subMinute(),
            'locked_at' => now(),
        ]);
        $foreignPrediction->options()->sync([$foreignOption->id]);

        $manager = User::factory()->create();
        $pool = Pool::factory()->predictionOnly()->for($manager, 'owner')->create([
            'season_id' => $season->id,
        ]);
        PoolMember::factory()->for($pool)->for($manager)->create([
            'role' => PoolMemberRole::Owner,
        ]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
        ]);

        $component = Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->assertDontSee('MEMBRE-AUTRE-POOL');

        $this->assertSame(
            [$poolEvent->id],
            $component->get('officialRounds')->first()->events->first()->poolEvents->pluck('id')->all(),
        );

        $component
            ->set('eventForm.answer_source', 'boolean')
            ->assertHasNoErrors()
            ->assertDontSee('MEMBRE-AUTRE-POOL');

        $this->assertSame(
            [$poolEvent->id],
            $component->get('officialRounds')->first()->events->first()->poolEvents->pluck('id')->all(),
        );
    }

    public function test_effective_deadline_locks_submissions_and_never_promotes_drafts(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        [$event, $member, $option] = $this->openEventWithMember(locksAt: now()->addSecond());
        $submitted = app(SubmitEventPrediction::class)->handle($event, $member, [$option->id], true);
        $otherMember = PoolMember::factory()->for($event->pool)->create(['draft_position' => 2]);
        $draft = app(SubmitEventPrediction::class)->handle($event, $otherMember, [], false);

        Carbon::setTestNow('2026-07-16 12:00:01');
        $this->assertSame(EventStatus::Locked, $event->fresh()->effectiveStatus());

        try {
            app(SubmitEventPrediction::class)->handle($event->fresh(), $member, [$option->id], true);
            $this->fail('The exact deadline should reject the prediction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $this->assertSame(PredictionStatus::Locked, $submitted->fresh()->status);
        $this->assertSame(PredictionStatus::Draft, $draft->fresh()->status);
    }

    public function test_rejected_local_prediction_context_never_synchronizes_or_locks_existing_answers(): void
    {
        foreach (['cross_pool', 'removed', 'terminal'] as $scenario) {
            $round = Round::factory()->create();
            $event = Event::factory()->for($round)->create([
                'pool_id' => $round->pool_id,
                'status' => EventStatus::Draft,
                'opens_at' => now()->subHour(),
                'locks_at' => now()->subMinute(),
            ]);
            $option = EventOption::factory()->for($event)->create();
            $member = PoolMember::factory()->for($event->pool)->create();
            $prediction = EventPrediction::factory()->create([
                'event_id' => $event->id,
                'pool_member_id' => $member->id,
                'status' => PredictionStatus::Submitted,
                'submitted_at' => now()->subHour(),
                'locked_at' => null,
            ]);
            $prediction->options()->sync([$option->id]);
            $requestMember = $member;

            if ($scenario === 'cross_pool') {
                $requestMember = PoolMember::factory()->create();
            } elseif ($scenario === 'removed') {
                $member->update(['status' => PoolMemberStatus::Removed, 'removed_at' => now()]);
            } else {
                Pool::query()->whereKey($event->pool_id)->update(['status' => PoolStatus::Completed->value]);
            }

            try {
                app(SubmitEventPrediction::class)->handle($event, $requestMember, [$option->id]);
                $this->fail("The {$scenario} local prediction context should be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('prediction', $exception->errors());
            }

            $this->assertSame(EventStatus::Draft, $event->fresh()->status);
            $this->assertSame(PredictionStatus::Submitted, $prediction->fresh()->status);
            $this->assertNull($prediction->fresh()->locked_at);
        }
    }

    public function test_rejected_official_prediction_context_never_synchronizes_or_locks_existing_answers(): void
    {
        foreach (['cross_pool', 'removed', 'terminal'] as $scenario) {
            $season = Season::factory()->create();
            $round = SeasonRound::factory()->for($season)->create();
            $event = SeasonEvent::factory()->for($round, 'round')->create([
                'status' => EventStatus::Draft,
                'opens_at' => now()->subHour(),
                'locks_at' => now()->subMinute(),
                'options_locked_at' => null,
            ]);
            $option = SeasonEventOption::factory()->for($event, 'event')->create();
            $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
            $member = PoolMember::factory()->for($pool)->create();
            $poolEvent = PoolEvent::factory()->create([
                'pool_id' => $pool->id,
                'season_event_id' => $event->id,
            ]);
            $prediction = PoolEventPrediction::factory()->create([
                'pool_event_id' => $poolEvent->id,
                'pool_member_id' => $member->id,
                'status' => PredictionStatus::Submitted,
                'submitted_at' => now()->subHour(),
                'locked_at' => null,
            ]);
            $prediction->options()->sync([$option->id]);
            $requestMember = $member;

            if ($scenario === 'cross_pool') {
                $requestMember = PoolMember::factory()->create();
            } elseif ($scenario === 'removed') {
                $member->update(['status' => PoolMemberStatus::Removed, 'removed_at' => now()]);
            } else {
                Pool::query()->whereKey($pool->id)->update(['status' => PoolStatus::Completed->value]);
            }

            try {
                app(SubmitPoolEventPrediction::class)->handle($poolEvent, $requestMember, [$option->id]);
                $this->fail("The {$scenario} official prediction context should be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('prediction', $exception->errors());
            }

            $this->assertSame(EventStatus::Draft, $event->fresh()->status);
            $this->assertNull($event->fresh()->options_locked_at);
            $this->assertSame(PredictionStatus::Submitted, $prediction->fresh()->status);
            $this->assertNull($prediction->fresh()->locked_at);
        }
    }

    public function test_stored_open_event_does_not_accept_predictions_before_its_opening_time(): void
    {
        $event = Event::factory()->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->addHour(),
            'locks_at' => now()->addHours(2),
        ]);
        $option = EventOption::factory()->for($event)->create();
        $event->update(['status' => EventStatus::Open]);
        $member = PoolMember::factory()->for($event->pool)->create();

        $this->assertSame(EventStatus::Draft, $event->effectiveStatus());
        $this->expectException(ValidationException::class);

        app(SubmitEventPrediction::class)->handle($event, $member, [$option->id]);
    }

    public function test_pool_competition_mode_cannot_change_after_activation(): void
    {
        $pool = \App\Models\Pool::factory()->predictionOnly()->create(['status' => PoolStatus::Active]);

        $this->expectException(ValidationException::class);

        $pool->update(['competition_mode' => PoolCompetitionMode::Hybrid]);
    }

    public function test_critical_pool_settings_cannot_change_after_the_draft_starts(): void
    {
        $pool = Pool::factory()->create(['status' => PoolStatus::Draft]);
        $otherSeason = Season::factory()->create();
        $changes = [
            'season_id' => $otherSeason->id,
            'competition_mode' => PoolCompetitionMode::PredictionOnly,
            'max_members' => $pool->max_members + 1,
            'picks_per_member' => $pool->picks_per_member + 1,
            'draft_mode' => DraftMode::Linear,
            'exclusive_draft' => ! $pool->exclusive_draft,
        ];

        foreach ($changes as $attribute => $value) {
            try {
                $pool->refresh()->update([$attribute => $value]);
                $this->fail("The critical pool setting [{$attribute}] should be immutable after the draft starts.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pool', $exception->errors());
            }
        }

        $pool->refresh()->update(['name' => 'Un nom encore modifiable']);
        $this->assertSame('Un nom encore modifiable', $pool->fresh()->name);
    }

    public function test_removed_member_cannot_submit_a_local_event_prediction_from_a_stale_page(): void
    {
        [$event, $member, $option] = $this->openEventWithMember();
        PoolMember::query()->whereKey($member->id)->update([
            'status' => PoolMemberStatus::Removed,
            'removed_at' => now(),
        ]);

        try {
            app(SubmitEventPrediction::class)->handle($event, $member, [$option->id]);
            $this->fail('A removed pool member should not be able to submit a prediction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $this->assertDatabaseCount('event_predictions', 0);
    }

    public function test_non_exclusive_draft_cannot_start_when_one_roster_exceeds_available_houseguests(): void
    {
        $season = Season::factory()->create();
        Houseguest::factory()->count(2)->for($season)->create();
        $owner = User::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            ...$this->poolData($season, PoolCompetitionMode::RosterOnly),
            'picks_per_member' => 3,
            'exclusive_draft' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(\App\Actions\Drafts\StartDraft::class)->handle($pool->draft, $owner);
    }

    public function test_invalid_event_transition_is_rejected(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Draft, 'opens_at' => null]);

        $this->expectException(ValidationException::class);
        app(SynchronizeEventLifecycle::class)->transition($event, EventStatus::Published);
    }

    public function test_published_local_event_definition_and_options_are_immutable_without_predictions(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Draft]);
        $option = EventOption::factory()->for($event)->create();
        $event->update(['status' => EventStatus::Published]);

        try {
            $event->update(['name' => 'Changed after publication']);
            $this->fail('Published local event metadata should be frozen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        try {
            $option->update(['label' => 'Changed after publication']);
            $this->fail('Published local event options should be frozen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        $draftEvent = Event::factory()->create(['status' => EventStatus::Draft]);
        try {
            $option->refresh()->update(['event_id' => $draftEvent->id]);
            $this->fail('A frozen local option should not be movable to another event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }
    }

    public function test_local_and_official_cancellations_require_a_reason_and_never_create_points(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, $this->poolData($season, PoolCompetitionMode::PredictionOnly));
        $round = Round::factory()->for($pool)->create();
        $localEvent = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::Draft,
            'opens_at' => now()->addDay(),
        ]);

        try {
            app(SynchronizeEventLifecycle::class)->transition($localEvent, EventStatus::Cancelled, $owner);
            $this->fail('A local cancellation without a reason should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancellation_reason', $exception->errors());
        }

        app(SynchronizeEventLifecycle::class)->transition($localEvent, EventStatus::Cancelled, $owner, 'Émission reportée.');

        $administrator = User::factory()->admin()->create();
        $officialRound = SeasonRound::factory()->for($season)->create();
        $officialEvent = SeasonEvent::factory()->for($officialRound, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->addDay(),
        ]);

        try {
            app(TransitionSeasonEvent::class)->transition($officialEvent, EventStatus::Cancelled, $administrator);
            $this->fail('An official cancellation without a reason should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancellation_reason', $exception->errors());
        }

        app(TransitionSeasonEvent::class)->transition($officialEvent, EventStatus::Cancelled, $administrator, 'Décision de la production.');

        $this->assertSame(EventStatus::Cancelled, $localEvent->fresh()->status);
        $this->assertSame(EventStatus::Cancelled, $officialEvent->fresh()->status);
        $this->assertDatabaseCount('event_results', 0);
        $this->assertDatabaseCount('season_event_results', 0);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertSame('Émission reportée.', AuditLog::query()
            ->where('auditable_type', $localEvent->getMorphClass())
            ->where('auditable_id', $localEvent->id)
            ->latest('id')->firstOrFail()->metadata['reason']);
        $this->assertSame('Décision de la production.', AuditLog::query()
            ->where('auditable_type', $officialEvent->getMorphClass())
            ->where('auditable_id', $officialEvent->id)
            ->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_result_entered_events_cannot_be_cancelled_while_publication_is_in_progress(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration(
            $owner,
            $this->poolData($season, PoolCompetitionMode::PredictionOnly),
        );
        $round = Round::factory()->for($pool)->create();
        $localEvent = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::ResultEntered,
        ]);
        $administrator = User::factory()->admin()->create();
        $officialEvent = SeasonEvent::factory()->create(['status' => EventStatus::ResultEntered]);

        foreach ([
            fn () => app(SynchronizeEventLifecycle::class)->transition(
                $localEvent,
                EventStatus::Cancelled,
                $owner,
                'Too late.',
            ),
            fn () => app(TransitionSeasonEvent::class)->transition(
                $officialEvent,
                EventStatus::Cancelled,
                $administrator,
                'Too late.',
            ),
        ] as $cancelResultEnteredEvent) {
            try {
                $cancelResultEnteredEvent();
                $this->fail('A result awaiting publication must not be cancellable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('event', $exception->errors());
            }
        }

        $this->assertSame(EventStatus::ResultEntered, $localEvent->fresh()->status);
        $this->assertSame(EventStatus::ResultEntered, $officialEvent->fresh()->status);
    }

    public function test_explicit_none_option_is_mutually_exclusive_in_local_and_official_predictions(): void
    {
        $localRound = Round::factory()->create();
        $localEvent = Event::factory()->for($localRound)->create([
            'pool_id' => $localRound->pool_id,
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $localOption = EventOption::factory()->for($localEvent)->create();
        $localNone = EventOption::factory()->for($localEvent)->create(['is_none' => true]);
        $localEvent->update(['status' => EventStatus::Open]);
        $localMember = PoolMember::factory()->for($localEvent->pool)->create();

        try {
            app(SubmitEventPrediction::class)->handle($localEvent, $localMember, [$localOption->id, $localNone->id]);
            $this->fail('The local none option should be exclusive.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $officialEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $officialOption = SeasonEventOption::factory()->for($officialEvent, 'event')->create();
        $officialNone = SeasonEventOption::factory()->for($officialEvent, 'event')->create(['is_none' => true]);
        $officialEvent->update(['status' => EventStatus::Open, 'options_locked_at' => now()]);
        $officialPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $officialMember = PoolMember::factory()->for($officialPool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $officialPool->id,
            'season_event_id' => $officialEvent->id,
        ]);

        $this->expectException(ValidationException::class);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $officialMember, [$officialOption->id, $officialNone->id]);
    }

    /** @return array{Event, PoolMember, EventOption} */
    private function openEventWithMember(int $minimum = 1, mixed $locksAt = null): array
    {
        $round = Round::factory()->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $round->pool_id,
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => $locksAt ?? now()->addHour(),
            'prediction_min_selections' => $minimum,
            'prediction_max_selections' => 3,
        ]);
        $option = EventOption::factory()->for($event)->create();
        $event->update(['status' => EventStatus::Open]);
        $member = PoolMember::factory()->for($event->pool)->create();

        return [$event, $member, $option];
    }

    /** @return array<string, mixed> */
    private function poolData(Season $season, PoolCompetitionMode $mode): array
    {
        return [
            'season_id' => $season->id,
            'name' => 'Test pool',
            'description' => null,
            'timezone' => 'America/Toronto',
            'competition_mode' => $mode,
            'max_members' => 12,
            'picks_per_member' => 1,
            'draft_mode' => DraftMode::Snake,
            'exclusive_draft' => true,
        ];
    }
}
