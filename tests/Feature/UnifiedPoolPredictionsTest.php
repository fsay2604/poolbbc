<?php

namespace Tests\Feature;

use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonRound;
use App\Models\User;
use App\Support\PredictionEventView;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class UnifiedPoolPredictionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_official_and_local_predictions_share_one_journey_without_key_collisions(): void
    {
        [$pool, $member, $poolEvent, $officialOption, $localEvent, $localOption] = $this->mixedJourney();

        $this->assertSame($poolEvent->id, $localEvent->id);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('totalCount', 2)
            ->assertSet("selections.official-{$poolEvent->id}", null)
            ->assertSet("selections.local-{$localEvent->id}", null)
            ->set("selections.official-{$poolEvent->id}", $officialOption->id)
            ->assertDispatched('prediction-autosaved')
            ->call('submit', "official-{$poolEvent->id}")
            ->assertHasNoErrors()
            ->set("selections.local-{$localEvent->id}", $localOption->id)
            ->assertDispatched('prediction-autosaved')
            ->call('submit', "local-{$localEvent->id}")
            ->assertHasNoErrors()
            ->assertSet('submittedCount', 2);

        $this->assertDatabaseHas('pool_event_predictions', [
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('event_predictions', [
            'event_id' => $localEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted->value,
        ]);
    }

    public function test_roster_only_local_events_are_hidden_and_rejected_before_lifecycle_mutation(): void
    {
        [$pool, $member] = $this->mixedJourney();
        $round = Round::factory()->for($pool)->create(['position' => 2]);
        $rosterEvent = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'mode' => EventMode::Roster,
            'name' => 'ÉVÉNEMENT-ROSTER-CACHÉ',
            'status' => EventStatus::Draft,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);
        $rosterOption = EventOption::factory()->for($rosterEvent)->create();

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('totalCount', 2)
            ->assertDontSee('ÉVÉNEMENT-ROSTER-CACHÉ');

        try {
            app(SubmitEventPrediction::class)->handle($rosterEvent, $member, [$rosterOption->id]);
            $this->fail('A roster-only event must reject predictions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }

        $this->assertSame(EventStatus::Draft, $rosterEvent->fresh()->status);
        $this->assertDatabaseCount('event_predictions', 0);
    }

    public function test_stale_malformed_and_cross_pool_keys_return_controlled_errors_without_writes(): void
    {
        [$pool, $member, $poolEvent] = $this->mixedJourney();
        $foreignRound = Round::factory()->create();
        $foreignEvent = Event::factory()->for($foreignRound)->create([
            'pool_id' => $foreignRound->pool_id,
            'status' => EventStatus::Draft,
        ]);
        $foreignOption = EventOption::factory()->for($foreignEvent)->create();

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->call('submit', 'local-999999')
            ->assertHasErrors('selections.local-999999')
            ->call('submit', $poolEvent->id)
            ->assertHasErrors('selections')
            ->call('submit', 'not-an-event')
            ->assertHasErrors('selections')
            ->set("selections.local-{$foreignEvent->id}", $foreignOption->id)
            ->assertHasErrors("selections.local-{$foreignEvent->id}");

        $this->assertDatabaseCount('event_predictions', 0);
        $this->assertDatabaseCount('pool_event_predictions', 0);
    }

    public function test_a_member_removed_after_mount_gets_a_controlled_error_without_a_stale_write(): void
    {
        [$pool, $member, $poolEvent, $officialOption] = $this->mixedJourney();
        $component = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->set("selections.official-{$poolEvent->id}", $officialOption->id)
            ->assertDispatched('prediction-autosaved');

        $member->update(['status' => 'removed', 'removed_at' => now()]);

        $component
            ->call('submit', "official-{$poolEvent->id}")
            ->assertHasErrors(["selections.official-{$poolEvent->id}"])
            ->assertNotDispatched('prediction-submitted');

        $this->assertDatabaseMissing('pool_event_predictions', [
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted->value,
        ]);
    }

    public function test_only_the_members_own_answers_are_hydrated_before_reveal(): void
    {
        [$pool, $member, $poolEvent, $officialOption, $localEvent, $localOption] = $this->mixedJourney();
        $opponentUser = User::factory()->create(['name' => 'RÉPONSE-ADVERSAIRE-CACHÉE']);
        $opponent = PoolMember::factory()->for($pool)->for($opponentUser)->create(['draft_position' => 2]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $opponent, [$officialOption->id]);
        app(SubmitEventPrediction::class)->handle($localEvent, $opponent, [$localOption->id]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$officialOption->id]);
        app(SubmitEventPrediction::class)->handle($localEvent, $member, [$localOption->id]);

        $component = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertDontSee('RÉPONSE-ADVERSAIRE-CACHÉE');

        $events = $component->get('predictionEvents');
        $this->assertCount(2, $events);
        $events->each(function (PredictionEventView $event): void {
            $this->assertNotEmpty($event->selectedOptionIds);
            $this->assertSame([], $event->revealedPredictions);
        });
    }

    public function test_local_answers_reveal_after_lock_and_official_after_publish_visibility_waits_for_publish(): void
    {
        [$pool, $member, $poolEvent, $officialOption, $localEvent, $localOption] = $this->mixedJourney();
        $poolEvent->update(['visibility' => 'after_publish']);
        $opponentUser = User::factory()->create(['name' => 'ADVERSAIRE-RÉVÉLÉ']);
        $opponent = PoolMember::factory()->for($pool)->for($opponentUser)->create(['draft_position' => 2]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $opponent, [$officialOption->id]);
        app(SubmitEventPrediction::class)->handle($localEvent, $opponent, [$localOption->id]);

        app(SynchronizeEventLifecycle::class)->transition($localEvent, EventStatus::Locked);
        $administrator = User::factory()->admin()->create();
        $officialEvent = app(TransitionSeasonEvent::class)->transition(
            $poolEvent->seasonEvent,
            EventStatus::Locked,
            $administrator,
        );

        $lockedEvents = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->get('predictionEvents');
        $officialView = $lockedEvents->firstWhere('key', "official-{$poolEvent->id}");
        $localView = $lockedEvents->firstWhere('key', "local-{$localEvent->id}");

        $this->assertSame([], $officialView->revealedPredictions);
        $this->assertSame('ADVERSAIRE-RÉVÉLÉ', $localView->revealedPredictions[0]['member_name']);

        $officialEvent = app(TransitionSeasonEvent::class)->transition(
            $officialEvent,
            EventStatus::ResultEntered,
            $administrator,
        );
        app(TransitionSeasonEvent::class)->transition($officialEvent, EventStatus::Published, $administrator);

        $publishedOfficialView = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->get('predictionEvents')
            ->firstWhere('key', "official-{$poolEvent->id}");

        $this->assertSame('ADVERSAIRE-RÉVÉLÉ', $publishedOfficialView->revealedPredictions[0]['member_name']);
    }

    public function test_mixed_prediction_query_budget_does_not_grow_per_event(): void
    {
        [$pool, $member, $poolEvent, , $localEvent] = $this->mixedJourney();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($poolEvent->seasonEvent, EventStatus::Locked, $administrator);
        app(SynchronizeEventLifecycle::class)->transition($localEvent, EventStatus::Locked);

        $queryCount = 0;
        $isCounting = false;
        DB::listen(function () use (&$queryCount, &$isCounting): void {
            if ($isCounting) {
                $queryCount++;
            }
        });

        $isCounting = true;
        Livewire::actingAs($member->user)->test('pools.predictions', ['pool' => $pool]);
        $isCounting = false;
        $smallJourneyQueries = $queryCount;

        $officialRound = $poolEvent->seasonEvent->round;
        $localRound = $localEvent->round;
        foreach (range(2, 21) as $position) {
            $officialEvent = SeasonEvent::factory()->for($officialRound, 'round')->create([
                'position' => $position,
                'status' => EventStatus::Locked,
                'opens_at' => now()->subDay(),
                'locks_at' => now()->subHour(),
            ]);
            PoolEvent::factory()->create([
                'pool_id' => $pool->id,
                'season_event_id' => $officialEvent->id,
                'mode' => EventMode::Prediction,
            ]);
            Event::factory()->for($localRound)->create([
                'pool_id' => $pool->id,
                'position' => $position,
                'mode' => EventMode::Prediction,
                'status' => EventStatus::Locked,
                'opens_at' => now()->subDay(),
                'locks_at' => now()->subHour(),
            ]);
        }

        $queryCount = 0;
        $isCounting = true;
        Livewire::actingAs($member->user)->test('pools.predictions', ['pool' => $pool]);
        $isCounting = false;

        $this->assertLessThanOrEqual(
            $smallJourneyQueries + 4,
            $queryCount,
            "Mixed prediction query budget grew from {$smallJourneyQueries} to {$queryCount} queries.",
        );
    }

    /**
     * @return array{Pool, PoolMember, PoolEvent, SeasonEventOption, Event, EventOption}
     */
    private function mixedJourney(): array
    {
        $season = Season::factory()->create();
        $officialRound = SeasonRound::factory()->for($season)->create([
            'name' => 'Ronde officielle',
            'position' => 1,
        ]);
        $officialEvent = SeasonEvent::factory()->for($officialRound, 'round')->create([
            'name' => 'Question officielle',
            'position' => 1,
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);
        $officialOption = SeasonEventOption::factory()->for($officialEvent, 'event')->create([
            'label' => 'Option officielle',
        ]);
        $officialEvent->update([
            'status' => EventStatus::Open,
            'options_locked_at' => now(),
        ]);

        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $officialEvent->id,
            'mode' => EventMode::Prediction,
        ]);

        $localRound = Round::factory()->for($pool)->create([
            'name' => 'Ronde du pool',
            'position' => 1,
        ]);
        $localEvent = Event::factory()->for($localRound)->create([
            'pool_id' => $pool->id,
            'name' => 'Question du pool',
            'position' => 1,
            'mode' => EventMode::Prediction,
            'status' => EventStatus::Draft,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);
        $localOption = EventOption::factory()->for($localEvent)->create([
            'label' => 'Option du pool',
        ]);
        $localEvent->update(['status' => EventStatus::Open]);

        return [$pool, $member->load('user'), $poolEvent, $officialOption, $localEvent, $localOption];
    }
}
