<?php

namespace Tests\Feature;

use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberStatus;
use App\Enums\PredictionStatus;
use App\Models\Draft;
use App\Models\Houseguest;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PoolFirstNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pool_member_can_use_the_canonical_prediction_journey_without_hydrating_an_opponents_answer(): void
    {
        [$pool, $member, $poolEvent, $option] = $this->predictionJourney();
        $opponent = PoolMember::factory()->for($pool)->create(['draft_position' => 2]);
        $opponentPrediction = PoolEventPrediction::factory()->create([
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $opponent->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);
        $opponentPrediction->options()->sync([$option->id]);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('rounds', fn ($rounds): bool => $rounds->first()->events->first()->poolEvents->first()->predictions->isEmpty())
            ->set("selections.{$poolEvent->id}", $option->id)
            ->assertDispatched('prediction-autosaved')
            ->call('submit', $poolEvent->id)
            ->assertHasNoErrors()
            ->assertDispatched('prediction-submitted')
            ->assertSet('submittedCount', 1);

        $this->assertDatabaseHas('pool_event_predictions', [
            'pool_event_id' => $poolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted->value,
        ]);

        $administrator = User::factory()->admin()->create();
        $event = $poolEvent->seasonEvent;
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);
        app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$option->id]);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('rounds', fn ($rounds): bool => $rounds->first()->events->first()->poolEvents->first()->predictions->count() === 2)
            ->assertSee('Résultat officiel')
            ->assertSee($opponent->user->name);

        Livewire::actingAs($member->user)
            ->test('pools.events', ['pool' => $pool])
            ->assertSee('Résultats officiels')
            ->assertSee($event->name)
            ->assertSee($opponent->user->name);
    }

    public function test_after_publish_visibility_hides_competing_predictions_until_the_result_is_published(): void
    {
        [$pool, $member, $poolEvent, $option] = $this->predictionJourney();
        $poolEvent->update(['visibility' => 'after_publish']);
        $opponentUser = User::factory()->create(['name' => 'REPONSE-CACHEE-JUSQU-A-PUBLICATION']);
        $opponent = PoolMember::factory()->for($pool)->for($opponentUser)->create(['draft_position' => 2]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $opponent, [$option->id]);
        $administrator = User::factory()->admin()->create();
        $event = $poolEvent->seasonEvent;

        $event = app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $lockedComponent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertDontSee('REPONSE-CACHEE-JUSQU-A-PUBLICATION');
        $this->assertCount(
            0,
            $lockedComponent->get('rounds')->first()->events->first()->poolEvents->first()->predictions,
        );

        $event = app(TransitionSeasonEvent::class)->transition($event, EventStatus::ResultEntered, $administrator);

        $resultEnteredComponent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertDontSee('REPONSE-CACHEE-JUSQU-A-PUBLICATION');
        $this->assertCount(
            0,
            $resultEnteredComponent->get('rounds')->first()->events->first()->poolEvents->first()->predictions,
        );

        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Published, $administrator);

        $publishedComponent = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSee('REPONSE-CACHEE-JUSQU-A-PUBLICATION');
        $this->assertCount(
            1,
            $publishedComponent->get('rounds')->first()->events->first()->poolEvents->first()->predictions,
        );
    }

    public function test_prediction_autosave_requeries_official_rounds_without_leaking_another_pools_event(): void
    {
        [$pool, $member, $poolEvent, $option] = $this->predictionJourney();
        $foreignPool = Pool::factory()->predictionOnly()->create(['season_id' => $pool->season_id]);
        $foreignUser = User::factory()->create(['name' => 'MEMBRE-PREDICTION-ETRANGER']);
        $foreignMember = PoolMember::factory()->for($foreignPool)->for($foreignUser)->create();
        $foreignPoolEvent = PoolEvent::factory()->create([
            'pool_id' => $foreignPool->id,
            'season_event_id' => $poolEvent->season_event_id,
            'mode' => EventMode::Prediction,
        ]);
        app(SubmitPoolEventPrediction::class)->handle($foreignPoolEvent, $foreignMember, [$option->id]);

        $component = Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->set("selections.{$poolEvent->id}", $option->id)
            ->assertDispatched('prediction-autosaved')
            ->assertDontSee('MEMBRE-PREDICTION-ETRANGER');

        $loadedPoolEvents = $component->get('rounds')->first()->events->first()->poolEvents;

        $this->assertSame([$poolEvent->id], $loadedPoolEvents->pluck('id')->all());
        $this->assertSame(
            [$poolEvent->id],
            $loadedPoolEvents->flatMap->predictions->pluck('pool_event_id')->unique()->values()->all(),
        );
    }

    public function test_incomplete_revision_autosave_refreshes_progress_and_targets_the_nested_selection_loading_state(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create(['name' => 'Semaine autosave', 'position' => 1]);
        Houseguest::factory()->count(2)->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'name' => 'Choix multiples',
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
            'prediction_min_selections' => 2,
            'prediction_max_selections' => 2,
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
            'prediction_min_selections' => 2,
            'prediction_max_selections' => 2,
        ]);
        $optionIds = $event->options()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, $optionIds, true);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('submittedCount', 1)
            ->assertSet('missingPredictions', [])
            ->assertSeeHtml('wire:target="selections.'.$poolEvent->id.',submit('.$poolEvent->id.')"')
            ->set("selections.{$poolEvent->id}", [$optionIds[0]])
            ->assertSet('submittedCount', 0)
            ->assertSet('missingPredictions', [$event->name])
            ->assertSee('0 événement soumis sur 1');

        $prediction = $member->poolEventPredictions()->whereBelongsTo($poolEvent)->firstOrFail();
        $this->assertSame(PredictionStatus::Draft, $prediction->status);
        $this->assertCount(1, $prediction->options);
    }

    public function test_pool_pages_expose_persistent_pool_first_navigation(): void
    {
        [$pool, $member] = $this->predictionJourney();

        $this->actingAs($member->user)
            ->get(route('pools.show', $pool))
            ->assertOk()
            ->assertSee('Vue d’ensemble')
            ->assertSee('Prédictions')
            ->assertSee('Résultats')
            ->assertSee('Classement');

        $this->actingAs($member->user)
            ->get(route('pools.predictions', $pool))
            ->assertOk()
            ->assertSee('Progression')
            ->assertSee('Brouillon enregistré automatiquement');
    }

    public function test_non_member_cannot_open_a_pool_prediction_journey(): void
    {
        [$pool] = $this->predictionJourney();

        $this->actingAs(User::factory()->create())
            ->get(route('pools.predictions', $pool))
            ->assertForbidden();
    }

    public function test_removed_member_cannot_submit_from_a_stale_prediction_page(): void
    {
        [, $member, $poolEvent, $option] = $this->predictionJourney();
        $member->update(['status' => PoolMemberStatus::Removed]);

        $this->expectException(ValidationException::class);

        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);
    }

    public function test_roster_only_events_do_not_pollute_prediction_progress_or_journey(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $predictionEvent = SeasonEvent::factory()->for($round, 'round')->create(['name' => 'Prediction event']);
        $rosterEvent = SeasonEvent::factory()->for($round, 'round')->create(['name' => 'Roster event']);
        $pool = Pool::factory()->create(['season_id' => $season->id]);
        Draft::factory()->for($pool)->create();
        $member = PoolMember::factory()->for($pool)->create();
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $predictionEvent->id,
            'mode' => EventMode::Prediction,
        ]);
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $rosterEvent->id,
            'mode' => EventMode::Roster,
        ]);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('totalCount', 1)
            ->assertSee('Prediction event')
            ->assertDontSee('Roster event');

        Livewire::actingAs($member->user)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('summary.predictions_total', 1);
    }

    public function test_prediction_status_distinguishes_open_missing_locked_missing_and_expired_draft(): void
    {
        [$pool, $member, $poolEvent, $option] = $this->predictionJourney();

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSee('À faire');

        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id], false);
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($poolEvent->seasonEvent, EventStatus::Locked, $administrator);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSee('Brouillon expiré');

        $otherMember = PoolMember::factory()->for($pool)->create(['draft_position' => 2]);
        Livewire::actingAs($otherMember->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSee('Non soumise');
    }

    public function test_published_result_without_a_selection_is_not_presented_as_pending(): void
    {
        [$pool, $member, $poolEvent] = $this->predictionJourney();
        $administrator = User::factory()->admin()->create();
        $event = app(TransitionSeasonEvent::class)->transition(
            $poolEvent->seasonEvent,
            EventStatus::Locked,
            $administrator,
        );
        $event = app(TransitionSeasonEvent::class)->transition($event, EventStatus::ResultEntered, $administrator);
        SeasonEventResult::factory()->for($event, 'event')->create(['created_by' => $administrator->id]);
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Published, $administrator);

        Livewire::actingAs($member->user)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSee('Aucune sélection')
            ->assertDontSee('En attente de publication');

        Livewire::actingAs($member->user)
            ->test('pools.events', ['pool' => $pool])
            ->assertSee('Aucune sélection')
            ->assertDontSee('En attente de publication');
    }

    public function test_revealed_prediction_query_budget_is_invariant_as_events_are_added(): void
    {
        [$pool, $member, $poolEvent] = $this->predictionJourney();
        $administrator = User::factory()->admin()->create();
        app(TransitionSeasonEvent::class)->transition($poolEvent->seasonEvent, EventStatus::Locked, $administrator);
        $round = $poolEvent->seasonEvent->round;
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

        foreach (range(2, 21) as $position) {
            $event = SeasonEvent::factory()->for($round, 'round')->create([
                'position' => $position,
                'status' => EventStatus::Locked,
                'opens_at' => now()->subDay(),
                'locks_at' => now()->subHour(),
            ]);
            PoolEvent::factory()->create([
                'pool_id' => $pool->id,
                'season_event_id' => $event->id,
                'mode' => EventMode::Prediction,
            ]);
        }

        $queryCount = 0;
        $isCounting = true;
        Livewire::actingAs($member->user)->test('pools.predictions', ['pool' => $pool]);
        $isCounting = false;

        $this->assertLessThanOrEqual(
            $smallJourneyQueries + 2,
            $queryCount,
            "Prediction journey query budget grew from {$smallJourneyQueries} to {$queryCount} queries.",
        );
    }

    /** @return array{Pool, PoolMember, PoolEvent, \App\Models\SeasonEventOption} */
    private function predictionJourney(): array
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create(['name' => 'Semaine 1', 'position' => 1]);
        Houseguest::factory()->for($season)->create(['name' => 'Alex']);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'name' => 'Patron de la semaine',
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
            'position' => 1,
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $member = PoolMember::factory()->for($pool)->create();
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
        ]);

        return [$pool, $member->load('user'), $poolEvent, $event->options->firstOrFail()];
    }
}
