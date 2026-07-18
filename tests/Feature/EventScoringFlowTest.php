<?php

namespace Tests\Feature;

use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\StartDraft;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Actions\Pools\JoinPool;
use App\Actions\Pools\RemovePoolMember;
use App\Actions\Scoring\PreviewEventScore;
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
use Illuminate\Support\Facades\Cache;
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
        $pool = $this->createPoolOpenForRegistration($owner, [
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
            'pool_id' => $pool->id, 'created_by' => $owner->id, 'mode' => 'hybrid', 'status' => 'draft',
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
        $event->update(['status' => 'locked']);
        $firstPrediction = EventPrediction::query()->create([
            'event_id' => $event->id, 'pool_member_id' => $ownerMember->id, 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $firstPrediction->options()->sync([$firstOption->id]);
        $secondPrediction = EventPrediction::query()->create([
            'event_id' => $event->id, 'pool_member_id' => $secondMember->id, 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $secondPrediction->options()->sync([$secondOption->id]);

        Cache::put(BuildPoolLeaderboard::cacheKey($pool->id), 'stale');
        $firstResult = app(PublishEventResult::class)->handle($event, $owner, [$firstOption->id]);
        $this->assertNull(Cache::get(BuildPoolLeaderboard::cacheKey($pool->id)));
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
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Prédictions privées', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $otherMember = app(JoinPool::class)->handle($otherUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id, 'created_by' => $owner->id, 'status' => 'draft',
        ]);
        $option = EventOption::factory()->for($event)->create();
        $event->update(['status' => 'open']);

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
        $this->assertEqualsCanonicalizing(
            [$ownerMember->id, $otherMember->id],
            $component->get('localRespondedMemberIds')[$event->id],
        );

        $memberComponent = Livewire::actingAs($otherUser)->test('pools.events', ['pool' => $pool]);
        $this->assertSame([], $memberComponent->get('localRespondedMemberIds'));

        $event->update(['status' => 'locked']);
        $lockedComponent = Livewire::test('pools.events', ['pool' => $pool]);
        $lockedEvent = $lockedComponent->get('rounds')->first()->events->first();
        $this->assertCount(2, $lockedEvent->predictions);
    }

    public function test_pool_administrator_can_create_a_round_and_a_generic_boolean_event(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
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
            ->set('eventForm.save_as_template', true)
            ->set('eventForm.locks_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('createEvent')
            ->assertHasNoErrors();

        $event = $pool->events()->with('options')->firstOrFail();
        $this->assertSame('prediction', $event->mode->value);
        $this->assertSame(['Oui', 'Non'], $event->options->pluck('label')->all());
        $template = $pool->eventTypes()->firstOrFail();
        $this->assertSame($template->id, $event->event_type_id);
        $this->assertFalse($template->is_standard);

        $component
            ->set('eventForm.event_type_id', $template->id)
            ->set('eventForm.locks_at', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->call('createEvent')
            ->assertHasNoErrors();

        $this->assertSame(2, $pool->events()->where('event_type_id', $template->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'pool_id' => $pool->id,
            'action' => 'event_template.created',
        ]);
    }

    public function test_local_result_requires_a_fresh_score_preview_before_publication(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Aperçu obligatoire', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => 'draft',
        ]);
        $option = EventOption::factory()->for($event)->create();
        $event->update(['status' => 'locked']);

        $component = Livewire::actingAs($owner)
            ->test('pools.events', ['pool' => $pool])
            ->set("resultSelections.{$event->id}", [$option->id])
            ->call('publishResult', $event->id)
            ->assertHasErrors(["resultSelections.{$event->id}"])
            ->call('previewResult', $event->id)
            ->assertHasNoErrors()
            ->call('publishResult', $event->id)
            ->assertHasNoErrors()
            ->assertDispatched('result-published');

        $this->assertSame([], $component->get('resultPreviewFingerprints'));
        $this->assertDatabaseHas('event_results', ['event_id' => $event->id, 'version' => 1]);
    }

    public function test_score_preview_ignores_drafts_and_matches_the_non_negative_ledger_floor(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Plancher de points', 'description' => null,
            'timezone' => 'America/Toronto', 'competition_mode' => 'prediction_only',
            'max_members' => 2, 'picks_per_member' => 1, 'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $member = $pool->memberFor($owner);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'mode' => 'prediction',
            'status' => 'draft',
            'scoring_config' => [
                'owner' => ['points_per_match' => 0],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => -4],
                'allow_negative' => false,
            ],
        ]);
        $correctOption = EventOption::factory()->for($event)->create(['position' => 1]);
        $wrongOption = EventOption::factory()->for($event)->create(['position' => 2]);
        $event->update(['status' => 'locked']);
        $prediction = EventPrediction::query()->create([
            'event_id' => $event->id,
            'pool_member_id' => $member->id,
            'status' => 'draft',
        ]);
        $prediction->options()->sync([$wrongOption->id]);

        $draftPreview = app(PreviewEventScore::class)->handle($event, [$correctOption->id]);
        $this->assertSame(0, $draftPreview->first()['points']);

        $prediction->update(['status' => 'submitted', 'submitted_at' => now()]);
        $submittedPreview = app(PreviewEventScore::class)->handle($event, [$correctOption->id]);
        $this->assertSame(0, $submittedPreview->first()['points']);

        $result = app(PublishEventResult::class)->handle($event, $owner, [$correctOption->id]);
        $this->assertDatabaseHas('point_entries', [
            'event_result_id' => $result->id,
            'pool_member_id' => $member->id,
            'type' => 'prediction',
            'points' => -4,
        ]);
        $this->assertDatabaseHas('point_entries', [
            'event_result_id' => $result->id,
            'pool_member_id' => $member->id,
            'type' => 'adjustment',
            'points' => 4,
        ]);
        $this->assertSame(0, (int) $member->pointEntries()->sum('points'));
    }

    public function test_departed_member_keeps_completed_roster_in_preview_scoring_leaderboard_and_projection(): void
    {
        $owner = User::factory()->create();
        $departedUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Historique après départ', 'description' => null,
            'timezone' => 'America/Toronto', 'competition_mode' => 'roster_only',
            'max_members' => 2, 'picks_per_member' => 1, 'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $departedMember = app(JoinPool::class)->handle($departedUser, $pool->invite_code);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        app(MakeDraftPick::class)->handle($draft, $pool->memberFor($owner), $houseguests[0]);
        app(MakeDraftPick::class)->handle($draft->fresh(), $departedMember, $houseguests[1]);

        app(RemovePoolMember::class)->handle(
            $pool,
            $departedMember,
            $owner,
            'Le membre quitte après avoir complété son équipe.',
        );

        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'mode' => 'roster',
            'status' => 'draft',
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => ['points_per_correct' => 0, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        $option = EventOption::factory()->for($event)->create([
            'houseguest_id' => $houseguests[1]->id,
            'label' => $houseguests[1]->name,
            'value' => 'houseguest:'.$houseguests[1]->id,
        ]);
        $event->update(['status' => 'locked']);

        $preview = app(PreviewEventScore::class)->handle($event, [$option->id]);
        $this->assertSame(5, $preview->firstWhere('name', $departedUser->name)['points']);

        $result = app(PublishEventResult::class)->handle($event, $owner, [$option->id]);
        $this->assertDatabaseHas('point_entries', [
            'event_result_id' => $result->id,
            'pool_member_id' => $departedMember->id,
            'type' => 'roster',
            'points' => 5,
        ]);

        app(RebuildPoolScoreProjection::class)->handle($pool);
        $leaderboardRow = app(BuildPoolLeaderboard::class)
            ->handle($pool)
            ->firstWhere('member.id', $departedMember->id);

        $this->assertNotNull($leaderboardRow);
        $this->assertSame(5, $leaderboardRow['total_points']);
        $this->assertSame(1, $leaderboardRow['active_houseguests']);
        $this->assertDatabaseHas('pool_score_projections', [
            'pool_id' => $pool->id,
            'pool_member_id' => $departedMember->id,
            'total_points' => 5,
        ]);
    }
}
