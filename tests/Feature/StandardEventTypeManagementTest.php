<?php

namespace Tests\Feature;

use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\SynchronizeOfficialPoolEvents;
use App\Actions\Events\UpdateStandardEventType;
use App\Enums\EventMode;
use App\Enums\OfficialRoundTemplate;
use App\Enums\ResultPublicationMode;
use App\Models\AuditLog;
use App\Models\EventType;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use App\Support\StandardEventTypeCatalog;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StandardEventTypeManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_standard_type_seeding_is_complete_and_does_not_overwrite_an_admin_update(): void
    {
        $this->seed(EventTypeSeeder::class);
        $administrator = User::factory()->admin()->create();
        $eventType = EventType::query()->where('slug', StandardEventTypeCatalog::HEAD_OF_HOUSEHOLD)->sole();
        $originalStructuralAttributes = $eventType->only(['slug', 'pool_id', 'scope_key', 'is_standard', 'answer_source']);

        app(UpdateStandardEventType::class)->handle($eventType, $administrator, $this->formFor($eventType, [
            'name' => 'Patron personnalisé',
            'owner_points' => 9,
            'prediction_points' => 4,
        ]));
        $this->seed(EventTypeSeeder::class);

        $eventType->refresh();
        $this->assertSame(5, EventType::query()->whereNull('pool_id')->where('is_standard', true)->count());
        $this->assertSame('Patron personnalisé', $eventType->name);
        $this->assertSame(9, data_get($eventType->default_config, 'owner.points_per_match'));
        $this->assertSame(4, data_get($eventType->default_config, 'prediction.points_per_correct'));
        $this->assertSame(ResultPublicationMode::Immediate->value, data_get($eventType->default_config, 'result_publication_mode'));
        $this->assertSame($originalStructuralAttributes, $eventType->only(array_keys($originalStructuralAttributes)));

        $audit = AuditLog::query()
            ->where('action', 'event_type.updated')
            ->where('auditable_type', $eventType->getMorphClass())
            ->where('auditable_id', $eventType->id)
            ->sole();
        $this->assertSame(5, data_get($audit->metadata, 'before.default_config.owner.points_per_match'));
        $this->assertSame(9, data_get($audit->metadata, 'after.default_config.owner.points_per_match'));
    }

    public function test_policy_and_action_reject_non_admin_and_non_catalog_scopes(): void
    {
        $this->seed(EventTypeSeeder::class);
        $eventType = EventType::query()->where('slug', StandardEventTypeCatalog::EVICTION)->sole();
        $user = User::factory()->create();

        $this->assertFalse($user->can('update', $eventType));

        try {
            app(UpdateStandardEventType::class)->handle($eventType, $user, $this->formFor($eventType));
            $this->fail('A non-administrator updated a standard event type.');
        } catch (AuthorizationException) {
            $this->assertSame(-2, data_get($eventType->fresh()->default_config, 'owner.points_per_match'));
        }

        $administrator = User::factory()->admin()->create();
        $globalCustom = EventType::factory()->create([
            'pool_id' => null,
            'slug' => 'global-custom',
            'is_standard' => false,
        ]);
        $poolScoped = EventType::factory()->for(Pool::factory())->create([
            'slug' => StandardEventTypeCatalog::EVICTION,
            'is_standard' => true,
        ]);

        $this->assertFalse($administrator->can('update', $globalCustom));
        $this->assertFalse($administrator->can('update', $poolScoped));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_livewire_admin_page_lists_only_catalog_standards_and_validates_updates(): void
    {
        $this->seed(EventTypeSeeder::class);
        $administrator = User::factory()->admin()->create();
        $poolType = EventType::factory()->for(Pool::factory())->create(['name' => 'Modèle privé']);
        $eventType = EventType::query()->where('slug', StandardEventTypeCatalog::NOMINATION)->sole();

        $this->get(route('admin.event-types'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('admin.event-types'))
            ->assertForbidden();

        Livewire::actingAs($administrator)
            ->test('admin.event-types')
            ->assertSee($eventType->name)
            ->assertDontSee($poolType->name)
            ->call('edit', $eventType->id)
            ->assertSet('form.name', $eventType->name)
            ->set('form.prediction_min_selections', 4)
            ->set('form.prediction_max_selections', 2)
            ->call('save')
            ->assertHasErrors(['form.prediction_max_selections'])
            ->set('form.prediction_max_selections', 4)
            ->set('form.name', 'Mises en danger administrables')
            ->set('form.result_publication_mode', ResultPublicationMode::Manual->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('standard-event-type-updated')
            ->assertSet('showEditModal', false);

        $eventType->refresh();
        $this->assertSame('Mises en danger administrables', $eventType->name);
        $this->assertSame(ResultPublicationMode::Manual->value, data_get($eventType->default_config, 'result_publication_mode'));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($administrator)
            ->test('admin.event-types')
            ->call('edit', $poolType->id);
    }

    public function test_template_updates_only_affect_future_season_events_and_late_pool_sync_uses_each_snapshot(): void
    {
        $this->seed(EventTypeSeeder::class);
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $createRound = app(CreateSeasonRoundFromTemplate::class);
        $firstRound = $createRound->handle($season, $administrator, OfficialRoundTemplate::Standard, 'Avant modification');
        $eventType = EventType::query()->where('slug', StandardEventTypeCatalog::HEAD_OF_HOUSEHOLD)->sole();
        $firstEvent = $firstRound->events()->where('event_type_id', $eventType->id)->sole();

        app(UpdateStandardEventType::class)->handle($eventType, $administrator, $this->formFor($eventType, [
            'question' => 'Qui sera le prochain patron?',
            'default_mode' => EventMode::Prediction->value,
            'owner_points' => 9,
            'prediction_points' => 4,
            'exact_bonus' => 3,
            'result_publication_mode' => ResultPublicationMode::Manual->value,
        ]));
        $secondRound = $createRound->handle($season, $administrator, OfficialRoundTemplate::Standard, 'Après modification');
        $secondEvent = $secondRound->events()->where('event_type_id', $eventType->id)->sole();

        $this->assertSame(5, data_get($firstEvent->fresh()->scoring_config, 'owner.points_per_match'));
        $this->assertSame(9, data_get($secondEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(EventMode::Hybrid, $firstEvent->fresh()->default_mode);
        $this->assertSame(EventMode::Prediction, $secondEvent->default_mode);
        $this->assertSame(ResultPublicationMode::Immediate, $firstEvent->fresh()->result_publication_mode);
        $this->assertSame(ResultPublicationMode::Manual, $secondEvent->result_publication_mode);
        $this->assertNotSame($firstEvent->question, $secondEvent->question);

        $pool = Pool::factory()->create([
            'season_id' => $season->id,
            'scoring_config' => [],
        ]);
        app(SynchronizeOfficialPoolEvents::class)->handlePool($pool);

        $firstPoolEvent = PoolEvent::query()
            ->where('pool_id', $pool->id)
            ->where('season_event_id', $firstEvent->id)
            ->sole();
        $secondPoolEvent = PoolEvent::query()
            ->where('pool_id', $pool->id)
            ->where('season_event_id', $secondEvent->id)
            ->sole();
        $this->assertSame(5, data_get($firstPoolEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(9, data_get($secondPoolEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(2, data_get($firstPoolEvent->scoring_config, 'prediction.points_per_correct'));
        $this->assertSame(4, data_get($secondPoolEvent->scoring_config, 'prediction.points_per_correct'));
        $this->assertSame(3, data_get($secondPoolEvent->scoring_config, 'prediction.exact_match_bonus'));
        $this->assertSame(EventMode::Hybrid, $firstPoolEvent->mode);
        $this->assertSame(EventMode::Prediction, $secondPoolEvent->mode);

        $overriddenPool = Pool::factory()->create([
            'season_id' => $season->id,
            'scoring_config' => [
                'allow_negative' => true,
                'prediction' => ['points_per_correct' => 12],
                'event_types' => [
                    StandardEventTypeCatalog::HEAD_OF_HOUSEHOLD => ['owner_points' => 15],
                ],
            ],
        ]);
        app(SynchronizeOfficialPoolEvents::class)->handlePool($overriddenPool);
        $overriddenEvent = PoolEvent::query()
            ->whereBelongsTo($overriddenPool)
            ->whereBelongsTo($secondEvent, 'seasonEvent')
            ->sole();

        $this->assertSame(15, data_get($overriddenEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(12, data_get($overriddenEvent->scoring_config, 'prediction.points_per_correct'));
        $this->assertSame(3, data_get($overriddenEvent->scoring_config, 'prediction.exact_match_bonus'));
        $this->assertTrue(data_get($overriddenEvent->scoring_config, 'allow_negative'));
    }

    public function test_non_admin_cannot_create_an_official_round_from_a_template(): void
    {
        $season = Season::factory()->create();

        $this->expectException(AuthorizationException::class);
        try {
            app(CreateSeasonRoundFromTemplate::class)->handle(
                $season,
                User::factory()->create(),
                OfficialRoundTemplate::Finale,
                'Interdite',
            );
        } finally {
            $this->assertDatabaseCount('season_rounds', 0);
        }
    }

    public function test_draft_instance_changes_refresh_only_inherited_pool_event_rules(): void
    {
        $this->seed(EventTypeSeeder::class);
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $firstPool = Pool::factory()->create(['season_id' => $season->id, 'scoring_config' => []]);
        $secondPool = Pool::factory()->create(['season_id' => $season->id, 'scoring_config' => []]);
        $round = app(CreateSeasonRoundFromTemplate::class)->handle(
            $season,
            $administrator,
            OfficialRoundTemplate::Standard,
            'Ronde modifiable',
        );
        $event = $round->events()
            ->whereHas('eventType', fn ($query) => $query->where('slug', StandardEventTypeCatalog::NOMINATION))
            ->sole();
        $inheritedPoolEvent = PoolEvent::query()
            ->whereBelongsTo($firstPool)
            ->whereBelongsTo($event, 'seasonEvent')
            ->sole();
        $customPoolEvent = PoolEvent::query()
            ->whereBelongsTo($secondPool)
            ->whereBelongsTo($event, 'seasonEvent')
            ->sole();
        $customPoolEvent->update([
            'mode' => EventMode::Roster,
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'scoring_config' => [
                'owner' => ['points_per_match' => 99],
                'prediction' => ['points_per_correct' => 0, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
            'rules_customized_at' => now(),
        ]);

        $scoringConfig = $event->scoring_config;
        data_set($scoringConfig, 'owner.points_per_match', 8);
        $event->update([
            'default_mode' => EventMode::Prediction,
            'prediction_min_selections' => 3,
            'prediction_max_selections' => 3,
            'scoring_config' => $scoringConfig,
        ]);
        app(SynchronizeOfficialPoolEvents::class)->handleEvent($event);

        $inheritedPoolEvent->refresh();
        $customPoolEvent->refresh();
        $this->assertSame(EventMode::Prediction, $inheritedPoolEvent->mode);
        $this->assertSame([3, 3], [
            $inheritedPoolEvent->prediction_min_selections,
            $inheritedPoolEvent->prediction_max_selections,
        ]);
        $this->assertSame(8, data_get($inheritedPoolEvent->scoring_config, 'owner.points_per_match'));
        $this->assertSame(EventMode::Roster, $customPoolEvent->mode);
        $this->assertSame([1, 1], [
            $customPoolEvent->prediction_min_selections,
            $customPoolEvent->prediction_max_selections,
        ]);
        $this->assertSame(99, data_get($customPoolEvent->scoring_config, 'owner.points_per_match'));
    }

    public function test_backfill_migration_snapshots_existing_type_scoring_and_safe_legacy_defaults(): void
    {
        $ruleImmutabilityMigration = require database_path('migrations/2026_07_17_102611_enforce_canonical_rule_snapshot_immutability.php');
        $poolEventImmutabilityMigration = require database_path('migrations/2026_07_17_083933_enforce_pool_event_definition_immutability.php');
        $ruleImmutabilityMigration->down();
        $poolEventImmutabilityMigration->down();
        $contractMigration = require database_path('migrations/2026_07_17_083553_enforce_non_null_season_event_scoring_config.php');
        $contractMigration->down();
        $this->seed(EventTypeSeeder::class);
        $eventType = EventType::query()->where('slug', StandardEventTypeCatalog::EVICTION)->sole();
        $round = SeasonRound::factory()->create();
        $standardEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'event_type_id' => $eventType->id,
            'scoring_config' => null,
        ]);
        $legacyEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'event_type_id' => null,
            'scoring_config' => null,
        ]);
        PoolEvent::factory()->create([
            'season_event_id' => $legacyEvent->id,
            'scoring_config' => [
                'owner' => ['points_per_match' => 8],
                'prediction' => [
                    'points_per_correct' => 3,
                    'exact_match_bonus' => 2,
                    'wrong_answer_penalty' => -1,
                ],
                'allow_negative' => true,
            ],
        ]);
        $orphanLegacyEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'event_type_id' => null,
            'scoring_config' => null,
        ]);

        $migration = require database_path('migrations/2026_07_17_075319_backfill_season_event_scoring_config.php');
        $migration->up();
        $contractMigration->up();
        $poolEventImmutabilityMigration->up();
        $ruleImmutabilityMigration->up();

        $this->assertSame(-2, data_get($standardEvent->fresh()->scoring_config, 'owner.points_per_match'));
        $this->assertTrue(data_get($standardEvent->fresh()->scoring_config, 'allow_negative'));
        $this->assertSame(8, data_get($legacyEvent->fresh()->scoring_config, 'owner.points_per_match'));
        $this->assertSame(3, data_get($legacyEvent->fresh()->scoring_config, 'prediction.points_per_correct'));
        $this->assertTrue(data_get($legacyEvent->fresh()->scoring_config, 'allow_negative'));
        $this->assertSame(1, data_get($orphanLegacyEvent->fresh()->scoring_config, 'owner.points_per_match'));
        $this->assertFalse(data_get($orphanLegacyEvent->fresh()->scoring_config, 'allow_negative'));
    }

    /** @param array<string, mixed> $overrides */
    private function formFor(EventType $eventType, array $overrides = []): array
    {
        $config = app(StandardEventTypeCatalog::class)->normalizeDefaultConfig($eventType->slug, $eventType->default_config);

        return array_replace([
            'name' => $eventType->name,
            'question' => $config['question'],
            'default_mode' => $eventType->default_mode->value,
            'result_publication_mode' => $config['result_publication_mode'],
            'prediction_min_selections' => $config['prediction_min_selections'],
            'prediction_max_selections' => $config['prediction_max_selections'],
            'result_min_selections' => $config['result_min_selections'],
            'result_max_selections' => $config['result_max_selections'],
            'include_inactive_houseguests' => $config['include_inactive_houseguests'],
            'allow_none' => $config['allow_none'],
            'owner_points' => data_get($config, 'owner.points_per_match'),
            'prediction_points' => data_get($config, 'prediction.points_per_correct'),
            'exact_bonus' => data_get($config, 'prediction.exact_match_bonus'),
            'wrong_penalty' => data_get($config, 'prediction.wrong_answer_penalty'),
            'allow_negative' => $config['allow_negative'],
        ], $overrides);
    }
}
