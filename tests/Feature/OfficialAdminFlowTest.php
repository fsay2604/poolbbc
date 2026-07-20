<?php

namespace Tests\Feature;

use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Actions\Scoring\PreviewSeasonEventScore;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Enums\PoolMemberRole;
use App\Models\AuditLog;
use App\Models\Houseguest;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OfficialAdminFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_flux_wizard_creates_a_no_veto_round_with_explicit_deadlines(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();

        Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->set('seasonId', $season->id)
            ->set('template', OfficialRoundTemplate::NoVeto->value)
            ->set('roundName', 'Semaine sans veto')
            ->set('opensAt', '2026-07-20T18:00')
            ->set('locksAt', '2026-07-23T20:00')
            ->call('reviewWizard')
            ->assertSet('wizardStep', 2)
            ->call('createRound')
            ->assertHasNoErrors()
            ->assertDispatched('official-round-created');

        $round = SeasonRound::query()->where('name', 'Semaine sans veto')->firstOrFail();
        $this->assertCount(3, $round->events);
        $this->assertTrue($round->events->every(fn (SeasonEvent $event): bool => $event->opens_at->format('Y-m-d H:i') === '2026-07-20 18:00'));
        $this->assertTrue($round->events->every(fn (SeasonEvent $event): bool => $event->locks_at->format('Y-m-d H:i') === '2026-07-23 20:00'));
    }

    public function test_each_template_clone_is_independent_and_covers_every_official_structure(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $action = app(CreateSeasonRoundFromTemplate::class);

        $standard = $action->handle($season, $administrator, OfficialRoundTemplate::Standard, 'Standard');
        $noVeto = $action->handle($season, $administrator, OfficialRoundTemplate::NoVeto, 'Sans veto');
        $double = $action->handle($season, $administrator, OfficialRoundTemplate::DoubleEviction, 'Double');
        $finale = $action->handle($season, $administrator, OfficialRoundTemplate::Finale, 'Finale');

        $this->assertCount(4, $standard->events);
        $this->assertCount(3, $noVeto->events);
        $this->assertCount(4, $double->events);
        $this->assertCount(1, $finale->events);

        $standardEvents = $standard->events()->with('eventType')->get()->keyBy('eventType.slug');
        $nomination = $standardEvents->get('nomination');
        $eviction = $standardEvents->get('eviction');
        $this->assertSame([2, 4, 2, 4], [
            $nomination->prediction_min_selections,
            $nomination->prediction_max_selections,
            $nomination->result_min_selections,
            $nomination->result_max_selections,
        ]);
        $this->assertSame([1, 1, 0, 2], [
            $eviction->prediction_min_selections,
            $eviction->prediction_max_selections,
            $eviction->result_min_selections,
            $eviction->result_max_selections,
        ]);
        $this->assertSame(-2, data_get($eviction->eventType->default_config, 'owner.points_per_match'));
        $this->assertTrue(data_get($eviction->eventType->default_config, 'allow_negative'));

        $standard->events->first()->update(['name' => 'Titre adapté']);
        $this->assertNotSame('Titre adapté', $double->events->first()->fresh()->name);
    }

    public function test_score_preview_is_read_only_and_explains_points_per_pool_member(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        Houseguest::factory()->for($season)->create(['name' => 'Alex']);
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
                'prediction' => ['points_per_correct' => 4, 'exact_match_bonus' => 1, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);

        $this->expectException(ValidationException::class);
        app(PreviewSeasonEventScore::class)->handle($event, $administrator, [$option->id]);
    }

    public function test_score_preview_is_available_to_an_administrator_after_lock_and_remains_read_only(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        Houseguest::factory()->for($season)->create(['name' => 'Alex']);
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
                'prediction' => ['points_per_correct' => 4, 'exact_match_bonus' => 1, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $member, [$option->id]);
        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);

        $preview = app(PreviewSeasonEventScore::class)->handle($event, $administrator, [$option->id]);

        $this->assertCount(1, $preview);
        $this->assertSame(5, $preview->first()['total_points']);
        $this->assertDatabaseCount('point_entries', 0);
        $this->assertDatabaseCount('season_event_results', 0);
    }

    public function test_pool_manager_can_configure_official_scoring_before_a_response_with_an_audit_trail_but_other_managers_cannot_and_rules_then_freeze(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        Houseguest::factory()->for($season)->create(['name' => 'Alex']);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $option = $event->options->firstOrFail();

        $manager = User::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $manager->id,
        ]);
        PoolMember::factory()->for($pool)->for($manager)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
        ]);

        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->set("officialRuleForms.{$poolEvent->id}.mode", EventMode::Prediction->value)
            ->set("officialRuleForms.{$poolEvent->id}.prediction_min_selections", 1)
            ->set("officialRuleForms.{$poolEvent->id}.prediction_max_selections", 1)
            ->set("officialRuleForms.{$poolEvent->id}.owner_points", 6)
            ->set("officialRuleForms.{$poolEvent->id}.prediction_points", 7)
            ->set("officialRuleForms.{$poolEvent->id}.exact_bonus", 3)
            ->set("officialRuleForms.{$poolEvent->id}.wrong_penalty", -1)
            ->set("officialRuleForms.{$poolEvent->id}.allow_negative", true)
            ->call('saveOfficialRules', $poolEvent->id)
            ->assertHasNoErrors()
            ->assertDispatched('pool-event-rules-updated');

        $poolEvent->refresh();
        $this->assertSame(6, data_get($poolEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(7, data_get($poolEvent->scoring_config, 'prediction.points_per_correct'));
        $this->assertSame(3, data_get($poolEvent->scoring_config, 'prediction.exact_match_bonus'));
        $this->assertSame(-1, data_get($poolEvent->scoring_config, 'prediction.wrong_answer_penalty'));
        $this->assertTrue(data_get($poolEvent->scoring_config, 'allow_negative'));
        $this->assertNotNull($poolEvent->rules_customized_at);

        $auditLog = AuditLog::query()
            ->where('pool_id', $pool->id)
            ->where('user_id', $manager->id)
            ->where('action', 'pool_event.rules_updated')
            ->where('auditable_type', $poolEvent->getMorphClass())
            ->where('auditable_id', $poolEvent->id)
            ->sole();
        $this->assertSame(2, data_get($auditLog->metadata, 'before.scoring_config.prediction.points_per_correct'));
        $this->assertSame(7, data_get($auditLog->metadata, 'after.scoring_config.prediction.points_per_correct'));

        $otherManager = User::factory()->create();
        $otherPool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $otherManager->id,
        ]);
        PoolMember::factory()->for($otherPool)->for($otherManager)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);
        PoolMember::factory()->for($pool)->for($otherManager)->create([
            'role' => PoolMemberRole::Member,
            'draft_position' => 2,
        ]);

        Livewire::actingAs($otherManager)
            ->test('pools.events', ['pool' => $pool])
            ->assertForbidden();

        $participant = PoolMember::factory()->for($pool)->create(['draft_position' => 3]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $participant, [$option->id], false);

        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->set("officialRuleForms.{$poolEvent->id}.prediction_points", 9)
            ->call('saveOfficialRules', $poolEvent->id)
            ->assertHasErrors(['rules']);

        $this->assertSame(7, data_get($poolEvent->fresh()->scoring_config, 'prediction.points_per_correct'));
        $this->assertSame(1, AuditLog::query()->where('action', 'pool_event.rules_updated')->count());
    }

    public function test_future_official_events_inherit_each_pools_persistent_scoring_configuration(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $firstPoolScoring = Pool::defaultScoringConfig();
        $firstPoolScoring['prediction'] = [
            'points_per_correct' => 3,
            'exact_match_bonus' => 1,
            'wrong_answer_penalty' => -1,
        ];
        $firstPoolScoring['event_types']['head-of-household']['owner_points'] = 6;
        $secondPoolScoring = Pool::defaultScoringConfig();
        $secondPoolScoring['prediction'] = [
            'points_per_correct' => 7,
            'exact_match_bonus' => 4,
            'wrong_answer_penalty' => 0,
        ];
        $secondPoolScoring['event_types']['head-of-household']['owner_points'] = 9;

        $firstPool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'scoring_config' => $firstPoolScoring,
        ]);
        $secondPool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'scoring_config' => $secondPoolScoring,
        ]);
        $action = app(CreateSeasonRoundFromTemplate::class);
        $rounds = [
            $action->handle($season, $administrator, OfficialRoundTemplate::Standard, 'Semaine 1'),
            $action->handle($season, $administrator, OfficialRoundTemplate::Standard, 'Semaine 2'),
        ];

        foreach ($rounds as $round) {
            $event = $round->events()
                ->whereHas('eventType', fn ($query) => $query->where('slug', 'head-of-household'))
                ->firstOrFail();
            $firstPoolEvent = PoolEvent::query()
                ->whereBelongsTo($firstPool)
                ->whereBelongsTo($event, 'seasonEvent')
                ->firstOrFail();
            $secondPoolEvent = PoolEvent::query()
                ->whereBelongsTo($secondPool)
                ->whereBelongsTo($event, 'seasonEvent')
                ->firstOrFail();

            $this->assertSame(6, data_get($firstPoolEvent->scoring_config, 'owner.points_per_match'));
            $this->assertSame(3, data_get($firstPoolEvent->scoring_config, 'prediction.points_per_correct'));
            $this->assertSame(1, data_get($firstPoolEvent->scoring_config, 'prediction.exact_match_bonus'));
            $this->assertSame(-1, data_get($firstPoolEvent->scoring_config, 'prediction.wrong_answer_penalty'));
            $this->assertSame(9, data_get($secondPoolEvent->scoring_config, 'owner.points_per_match'));
            $this->assertSame(7, data_get($secondPoolEvent->scoring_config, 'prediction.points_per_correct'));
            $this->assertSame(4, data_get($secondPoolEvent->scoring_config, 'prediction.exact_match_bonus'));
            $this->assertSame(0, data_get($secondPoolEvent->scoring_config, 'prediction.wrong_answer_penalty'));
        }

        $this->assertSame($firstPoolScoring, $firstPool->fresh()->scoring_config);
        $this->assertSame($secondPoolScoring, $secondPool->fresh()->scoring_config);
    }

    public function test_an_official_event_cannot_be_rescheduled_into_the_past_and_leave_pool_projections_stale(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $inheritedPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $customizedPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $round = app(CreateSeasonRoundFromTemplate::class)->handle(
            $season,
            $administrator,
            OfficialRoundTemplate::Standard,
            'Future round',
            now()->addDays(2),
            now()->addDays(3),
        );
        $event = $round->events()->firstOrFail();
        $inheritedPoolEvent = PoolEvent::query()
            ->whereBelongsTo($inheritedPool)
            ->whereBelongsTo($event, 'seasonEvent')
            ->sole();
        $customizedPoolEvent = PoolEvent::query()
            ->whereBelongsTo($customizedPool)
            ->whereBelongsTo($event, 'seasonEvent')
            ->sole();
        $customizedPoolEvent->update([
            'visibility' => 'after_publish',
            'rules_customized_at' => now(),
        ]);
        $eventAttributes = ['default_mode', 'opens_at', 'locks_at', 'prediction_max_selections'];
        $originalEvent = collect($eventAttributes)
            ->mapWithKeys(fn (string $attribute): array => [$attribute => $event->getRawOriginal($attribute)])
            ->all();
        $originalInheritedProjection = $inheritedPoolEvent->only(['mode', 'visibility', 'prediction_max_selections', 'scoring_config']);
        $originalCustomizedProjection = $customizedPoolEvent->only(['mode', 'visibility', 'prediction_max_selections', 'scoring_config']);

        Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->call('startEdit', $event->id)
            ->set('eventForm.default_mode', EventMode::Prediction->value)
            ->set('eventForm.opens_at', now()->subDays(2)->format('Y-m-d\TH:i'))
            ->set('eventForm.locks_at', now()->subDay()->format('Y-m-d\TH:i'))
            ->set('eventForm.prediction_max_selections', 2)
            ->call('saveEvent')
            ->assertHasErrors(['eventForm.opens_at']);

        $freshEvent = $event->fresh();
        $this->assertSame(
            $originalEvent,
            collect($eventAttributes)
                ->mapWithKeys(fn (string $attribute): array => [$attribute => $freshEvent->getRawOriginal($attribute)])
                ->all(),
        );
        $this->assertSame($originalInheritedProjection, $inheritedPoolEvent->fresh()->only(array_keys($originalInheritedProjection)));
        $this->assertSame($originalCustomizedProjection, $customizedPoolEvent->fresh()->only(array_keys($originalCustomizedProjection)));
    }
}
