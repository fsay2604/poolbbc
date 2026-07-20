<?php

namespace Tests\Feature;

use App\Actions\Events\CreateSeasonEvent;
use App\Actions\Events\DeleteSeasonEvent;
use App\Actions\Events\DeleteSeasonRound;
use App\Actions\Events\UpdateSeasonEvent;
use App\Actions\Events\UpdateSeasonRound;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Models\AuditLog;
use App\Models\EventType;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OfficialStructureManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_administrator_can_create_a_standalone_official_event_from_a_standard_type(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create(['position' => 1]);
        SeasonEvent::factory()->for($round, 'round')->create(['position' => 1]);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $eventType = $this->standardEventType('eviction');

        $event = app(CreateSeasonEvent::class)->handle(
            $round,
            $administrator,
            $this->eventData($eventType, [
                'name' => 'Éviction surprise',
                'prediction_max_selections' => 2,
            ]),
        );

        $this->assertSame(2, $event->position);
        $this->assertSame($eventType->id, $event->event_type_id);
        $this->assertSame($eventType->answer_source, $event->answer_source);
        $this->assertSame(-2, data_get($event->scoring_config, 'owner.points_per_match'));
        $this->assertSame(2, $event->prediction_max_selections);
        $this->assertTrue(PoolEvent::query()->whereBelongsTo($pool)->whereBelongsTo($event, 'seasonEvent')->exists());

        $audit = AuditLog::query()->where('action', 'season_event.created')->sole();
        $this->assertSame($event->id, $audit->auditable_id);
        $this->assertNull(data_get($audit->metadata, 'before'));
        $this->assertSame('Éviction surprise', data_get($audit->metadata, 'after.name'));
    }

    public function test_a_non_administrator_cannot_create_a_standalone_official_event(): void
    {
        $round = SeasonRound::factory()->create();
        $eventType = $this->standardEventType('eviction');

        $this->expectException(AuthorizationException::class);

        app(CreateSeasonEvent::class)->handle($round, User::factory()->create(), $this->eventData($eventType));
    }

    public function test_round_metadata_updates_do_not_reschedule_child_events(): void
    {
        $administrator = User::factory()->admin()->create();
        $round = SeasonRound::factory()->create([
            'name' => 'Avant',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'opens_at' => now()->addDays(3),
            'locks_at' => now()->addDays(4),
        ]);
        $originalEventDates = $event->only(['opens_at', 'locks_at']);

        app(UpdateSeasonRound::class)->handle($round, $administrator, [
            'name' => 'Après',
            'starts_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(6)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame('Après', $round->fresh()->name);
        $this->assertEquals($originalEventDates, $event->fresh()->only(['opens_at', 'locks_at']));
        $audit = AuditLog::query()->where('action', 'season_round.updated')->sole();
        $this->assertSame('Avant', data_get($audit->metadata, 'before.name'));
        $this->assertSame('Après', data_get($audit->metadata, 'after.name'));
    }

    public function test_event_updates_are_audited_and_resynchronize_only_inherited_pool_rules(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $inheritedPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $customizedPool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $eventType = $this->standardEventType('nomination');
        $event = app(CreateSeasonEvent::class)->handle($round, $administrator, $this->eventData($eventType));
        $inheritedPoolEvent = $event->poolEvents()->where('pool_id', $inheritedPool->id)->sole();
        $customizedPoolEvent = $event->poolEvents()->where('pool_id', $customizedPool->id)->sole();
        $customizedPoolEvent->update([
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'rules_customized_at' => now(),
        ]);

        app(UpdateSeasonEvent::class)->handle($event, $administrator, $this->eventData($eventType, [
            'name' => 'Nominations adaptées',
            'prediction_max_selections' => 4,
        ]));

        $this->assertSame(4, $inheritedPoolEvent->fresh()->prediction_max_selections);
        $this->assertSame(1, $customizedPoolEvent->fresh()->prediction_max_selections);
        $audit = AuditLog::query()->where('action', 'season_event.updated')->sole();
        $this->assertSame($eventType->name, data_get($audit->metadata, 'before.name'));
        $this->assertSame('Nominations adaptées', data_get($audit->metadata, 'after.name'));
    }

    public function test_an_unused_draft_event_and_round_can_be_deleted_with_audit_snapshots(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $eventType = $this->standardEventType('eviction');
        $eventRound = SeasonRound::factory()->for($season)->create(['position' => 1]);
        $event = app(CreateSeasonEvent::class)->handle($eventRound, $administrator, $this->eventData($eventType));
        $poolEvent = PoolEvent::query()->whereBelongsTo($pool)->whereBelongsTo($event, 'seasonEvent')->sole();

        app(DeleteSeasonEvent::class)->handle($event, $administrator);

        $this->assertModelMissing($event);
        $this->assertModelMissing($poolEvent);
        $eventAudit = AuditLog::query()->where('action', 'season_event.deleted')->sole();
        $this->assertSame($event->name, data_get($eventAudit->metadata, 'before.name'));
        $this->assertNull(data_get($eventAudit->metadata, 'after'));

        $round = SeasonRound::factory()->for($season)->create(['position' => 2]);
        $roundEvent = app(CreateSeasonEvent::class)->handle($round, $administrator, $this->eventData($eventType));
        app(DeleteSeasonRound::class)->handle($round, $administrator);

        $this->assertModelMissing($round);
        $this->assertModelMissing($roundEvent);
        $roundAudit = AuditLog::query()->where('action', 'season_round.deleted')->sole();
        $this->assertSame([$roundEvent->id], data_get($roundAudit->metadata, 'before.event_ids'));
    }

    public function test_application_deletion_rejects_every_irreversible_dependency_without_an_audit(): void
    {
        foreach (['opened', 'frozen', 'prediction', 'result', 'points'] as $dependency) {
            [$event, $poolEvent] = $this->draftEventWithPoolEvent();

            match ($dependency) {
                'opened' => SeasonEvent::query()->whereKey($event->id)->update([
                    'status' => EventStatus::Open->value,
                    'opens_at' => now()->subHour(),
                ]),
                'frozen' => SeasonEvent::query()->whereKey($event->id)->update(['options_locked_at' => now()]),
                'prediction' => PoolEventPrediction::factory()->for($poolEvent)->create(),
                'result' => SeasonEventResult::factory()->for($event, 'event')->create(),
                'points' => $this->createPointEntry($poolEvent),
            };

            try {
                app(DeleteSeasonEvent::class)->handle($event->fresh(), User::factory()->admin()->create());
                $this->fail("The [{$dependency}] dependency should prevent deletion.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('eventDeletion', $exception->errors());
            }

            $this->assertModelExists($event);
            $this->assertFalse(AuditLog::query()
                ->where('action', 'season_event.deleted')
                ->where('auditable_id', $event->id)
                ->exists());
        }
    }

    public function test_round_deletion_is_atomic_when_one_child_event_is_protected(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $safeEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'position' => 1,
            'opens_at' => now()->addDay(),
            'locks_at' => now()->addDays(2),
        ]);
        $protectedEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'position' => 2,
            'status' => EventStatus::Open,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);

        try {
            app(DeleteSeasonRound::class)->handle($round, $administrator);
            $this->fail('The protected child should prevent round deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eventDeletion', $exception->errors());
        }

        $this->assertModelExists($round);
        $this->assertModelExists($safeEvent);
        $this->assertModelExists($protectedEvent);
        $this->assertFalse(AuditLog::query()->where('action', 'season_round.deleted')->exists());
    }

    public function test_sql_guards_reject_direct_deletion_of_protected_events_rounds_and_seasons(): void
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Open,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);

        foreach ([
            fn (): int => DB::table('season_events')->where('id', $event->id)->delete(),
            fn (): int => DB::table('season_rounds')->where('id', $round->id)->delete(),
            fn (): int => DB::table('seasons')->where('id', $season->id)->delete(),
        ] as $delete) {
            try {
                $delete();
                $this->fail('The database deletion guard should reject protected official structure.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('official', strtolower($exception->getMessage()));
            }
        }

        $this->assertModelExists($season);
        $this->assertModelExists($round);
        $this->assertModelExists($event);
    }

    public function test_structure_and_result_components_expose_only_their_own_responsibilities(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        SeasonEvent::factory()->for($round, 'round')->create([
            'name' => 'Événement verrouillé',
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);

        Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->assertSee('Rondes et événements officiels')
            ->assertDontSee('Historique des résultats')
            ->assertDontSee('Saisir le résultat');

        Livewire::actingAs($administrator)
            ->test('admin.official-results')
            ->assertSee('Résultats officiels')
            ->assertSee('Saisir le résultat')
            ->assertDontSee('Assistant de création d’une ronde');
    }

    public function test_contextual_official_pages_are_fixed_to_the_pool_season(): void
    {
        $administrator = User::factory()->admin()->create();
        $poolSeason = Season::factory()->create(['name' => 'Saison du pool']);
        $pool = Pool::factory()->create([
            'owner_id' => $administrator->id,
            'season_id' => $poolSeason->id,
        ]);
        PoolMember::factory()->for($pool)->for($administrator)->create();

        $poolRound = SeasonRound::factory()->for($poolSeason)->create(['name' => 'Ronde du pool']);
        SeasonEvent::factory()->for($poolRound, 'round')->create([
            'name' => 'Événement du pool',
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);

        $otherSeason = Season::factory()->create(['name' => 'Autre saison']);
        $otherRound = SeasonRound::factory()->for($otherSeason)->create(['name' => 'Ronde étrangère']);
        $otherEvent = SeasonEvent::factory()->for($otherRound, 'round')->create([
            'name' => 'Événement étranger',
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);

        Livewire::actingAs($administrator)
            ->test('admin.official-rounds', ['pool' => $pool])
            ->assertSet('seasonId', $poolSeason->id)
            ->assertSee('Ronde du pool')
            ->assertDontSee('Ronde étrangère');

        Livewire::actingAs($administrator)
            ->test('admin.official-results', ['pool' => $pool])
            ->assertSet('seasonId', $poolSeason->id)
            ->assertSee('Événement du pool')
            ->assertDontSee('Événement étranger');

        try {
            Livewire::actingAs($administrator)
                ->test('admin.official-rounds', ['pool' => $pool])
                ->call('startEdit', $otherEvent->id);
            $this->fail('A foreign-season event was accepted by the contextual structure page.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            Livewire::actingAs($administrator)
                ->test('admin.official-results', ['pool' => $pool])
                ->call('startResult', $otherEvent->id);
            $this->fail('A foreign-season event was accepted by the contextual result page.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function standardEventType(string $slug): EventType
    {
        $definition = app(StandardEventTypeCatalog::class)->find($slug);

        return EventType::query()->create([
            'pool_id' => null,
            'name' => $definition['name'],
            'slug' => $definition['slug'],
            'is_standard' => true,
            'default_mode' => $definition['default_mode'],
            'answer_source' => $definition['answer_source'],
            'default_config' => $definition['default_config'],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function eventData(EventType $eventType, array $overrides = []): array
    {
        $config = app(StandardEventTypeCatalog::class)
            ->normalizeDefaultConfig($eventType->slug, $eventType->default_config);

        return [
            'event_type_id' => $eventType->id,
            'name' => $eventType->name,
            'question' => $config['question'],
            'default_mode' => EventMode::Prediction->value,
            'opens_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'locks_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'prediction_min_selections' => $config['prediction_min_selections'],
            'prediction_max_selections' => $config['prediction_max_selections'],
            'result_min_selections' => $config['result_min_selections'],
            'result_max_selections' => $config['result_max_selections'],
            'result_publication_mode' => $config['result_publication_mode'],
            'include_inactive_houseguests' => $config['include_inactive_houseguests'],
            'allow_none' => $config['allow_none'],
            ...$overrides,
        ];
    }

    /** @return array{SeasonEvent, PoolEvent} */
    private function draftEventWithPoolEvent(): array
    {
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now()->addDay(),
            'locks_at' => now()->addDays(2),
        ]);
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
        ]);

        return [$event, $poolEvent];
    }

    private function createPointEntry(PoolEvent $poolEvent): PointEntry
    {
        $member = PoolMember::factory()->for($poolEvent->pool)->create();

        return PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'event_id' => null,
            'pool_event_id' => $poolEvent->id,
            'event_result_id' => null,
            'season_event_result_id' => null,
            'event_prediction_id' => null,
            'pool_event_prediction_id' => null,
            'type' => 'round_reconciliation',
            'points' => 1,
            'reason' => 'Test guard',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }
}
