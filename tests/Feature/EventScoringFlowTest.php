<?php

namespace Tests\Feature;

use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Actions\Scoring\PublishEventResult;
use App\Actions\Scoring\ScoringEngine;
use App\Models\AuditLog;
use App\Models\DraftPick;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\EventPrediction;
use App\Models\Houseguest;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EventScoringFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_hybrid_result_is_idempotent_and_a_correction_reverses_the_previous_score(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        [$firstHouseguest, $secondHouseguest] = Houseguest::factory()->count(2)->for($season)->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id, 'name' => 'Pointage hybride', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $secondMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);

        DraftPick::query()->create([
            'draft_id' => $pool->draft->id, 'pool_id' => $pool->id, 'pool_member_id' => $ownerMember->id,
            'houseguest_id' => $firstHouseguest->id, 'round_number' => 1, 'pick_number' => 1,
            'exclusive_claim' => 1, 'picked_at' => now(),
        ]);
        DraftPick::query()->create([
            'draft_id' => $pool->draft->id, 'pool_id' => $pool->id, 'pool_member_id' => $secondMember->id,
            'houseguest_id' => $secondHouseguest->id, 'round_number' => 1, 'pick_number' => 2,
            'exclusive_claim' => 1, 'picked_at' => now(),
        ]);

        $round = Round::factory()->for($pool)->create(['position' => 1]);
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id, 'created_by' => $owner->id, 'mode' => 'hybrid', 'status' => 'locked',
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        $firstOption = EventOption::factory()->for($event)->create([
            'houseguest_id' => $firstHouseguest->id, 'label' => $firstHouseguest->name, 'value' => 'houseguest:'.$firstHouseguest->id, 'position' => 1,
        ]);
        $secondOption = EventOption::factory()->for($event)->create([
            'houseguest_id' => $secondHouseguest->id, 'label' => $secondHouseguest->name, 'value' => 'houseguest:'.$secondHouseguest->id, 'position' => 2,
        ]);
        $firstPrediction = EventPrediction::query()->create([
            'event_id' => $event->id, 'pool_member_id' => $ownerMember->id, 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $firstPrediction->options()->sync([$firstOption->id]);
        $secondPrediction = EventPrediction::query()->create([
            'event_id' => $event->id, 'pool_member_id' => $secondMember->id, 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $secondPrediction->options()->sync([$secondOption->id]);

        $firstResult = app(PublishEventResult::class)->handle($event, $owner, [$firstOption->id]);
        $this->assertSame(7, (int) $ownerMember->pointEntries()->sum('points'));
        $this->assertSame(0, (int) $secondMember->pointEntries()->sum('points'));

        $entryCount = $firstResult->pointEntries()->count();
        app(ScoringEngine::class)->score($firstResult);
        $this->assertSame($entryCount, $firstResult->pointEntries()->count());

        $correctedResult = app(PublishEventResult::class)->handle($event->fresh(), $owner, [$secondOption->id], 'Résultat officiel corrigé');
        $this->assertSame(2, $correctedResult->version);
        $this->assertSame($firstResult->id, $correctedResult->supersedes_id);
        $this->assertSame(0, (int) $ownerMember->pointEntries()->sum('points'));
        $this->assertSame(7, (int) $secondMember->pointEntries()->sum('points'));
        $this->assertSame(2, $pool->fresh()->events()->firstOrFail()->results()->count());
        $this->assertSame(2, AuditLog::query()->where('pool_id', $pool->id)->whereIn('action', ['event.result_published', 'event.result_corrected'])->count());
    }

    public function test_other_members_predictions_are_not_hydrated_before_locking(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id, 'name' => 'Prédictions privées', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $otherMember = app(JoinPool::class)->handle($otherUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id, 'created_by' => $owner->id, 'status' => 'open',
        ]);
        $option = EventOption::factory()->for($event)->create();

        foreach ([$ownerMember, $otherMember] as $member) {
            $prediction = EventPrediction::query()->create([
                'event_id' => $event->id,
                'pool_member_id' => $member->id,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            $prediction->options()->sync([$option->id]);
        }

        $this->actingAs($owner);
        $component = Livewire::test('pools.events', ['pool' => $pool]);
        $loadedEvent = $component->get('rounds')->first()->events->first();

        $this->assertCount(1, $loadedEvent->predictions);
        $this->assertSame($ownerMember->id, $loadedEvent->predictions->first()->pool_member_id);

        $event->update(['status' => 'locked']);
        $lockedComponent = Livewire::test('pools.events', ['pool' => $pool]);
        $lockedEvent = $lockedComponent->get('rounds')->first()->events->first();
        $this->assertCount(2, $lockedEvent->predictions);
    }

    public function test_pool_administrator_can_create_a_round_and_a_generic_boolean_event(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id, 'name' => 'Événements génériques', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 4, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $this->actingAs($owner);

        $component = Livewire::test('pools.events', ['pool' => $pool])
            ->set('roundForm.name', 'Semaine 1')
            ->call('createRound')
            ->assertHasNoErrors();

        $round = $pool->rounds()->firstOrFail();
        $component
            ->set('eventForm.round_id', $round->id)
            ->set('eventForm.name', 'Utilisation du veto')
            ->set('eventForm.question', 'Le veto sera-t-il utilisé?')
            ->set('eventForm.answer_source', 'boolean')
            ->set('eventForm.locks_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('createEvent')
            ->assertHasNoErrors();

        $event = $pool->events()->with('options')->firstOrFail();
        $this->assertSame('prediction', $event->mode->value);
        $this->assertSame(['Oui', 'Non'], $event->options->pluck('label')->all());
    }
}
