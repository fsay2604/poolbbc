<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Events\DeleteEvent;
use App\Actions\Events\DeleteRound;
use App\Actions\Events\UpdateEvent;
use App\Actions\Events\UpdateRound;
use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LocalEventStructureManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_event_creation_uses_the_shared_option_builder(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();

        $event = app(CreateEvent::class)->handle($pool, $manager, [
            'round_id' => $round->id,
            'name' => 'Question boolÃ©enne',
            'mode' => EventMode::Prediction->value,
            'answer_source' => AnswerSource::Boolean->value,
            'locks_at' => now()->addDay(),
        ]);

        $this->assertCount(2, $event->options);
        $this->assertSame([1, 2], $event->options->pluck('position')->all());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'event.created')
            ->where('auditable_id', $event->id)
            ->exists());
    }

    public function test_a_pool_manager_can_update_draft_round_and_event_definitions_with_audits(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create([
            'name' => 'Avant',
            'position' => 1,
        ]);
        $targetRound = Round::factory()->for($pool)->create(['position' => 2]);
        $event = $this->draftEvent($round, $manager);
        $originalOption = EventOption::factory()->for($event)->create([
            'label' => 'Ancienne option',
            'value' => 'ancienne:1',
        ]);

        $updatedRound = app(UpdateRound::class)->handle($pool, $round, $manager, [
            'name' => 'AprÃ¨s',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);
        $updatedEvent = app(UpdateEvent::class)->handle(
            $pool,
            $event,
            $manager,
            $this->eventData($targetRound, [
                'name' => 'Question mise Ã  jour',
                'answer_source' => AnswerSource::Boolean->value,
            ]),
        );

        $this->assertSame('AprÃ¨s', $updatedRound->name);
        $this->assertSame('Question mise Ã  jour', $updatedEvent->name);
        $this->assertSame($targetRound->id, $updatedEvent->round_id);
        $this->assertSame(1, $updatedEvent->position);
        $this->assertCount(2, $updatedEvent->options);
        $this->assertModelMissing($originalOption);

        $roundAudit = AuditLog::query()->where('action', 'round.updated')->sole();
        $this->assertSame('Avant', data_get($roundAudit->metadata, 'before.name'));
        $this->assertSame('AprÃ¨s', data_get($roundAudit->metadata, 'after.name'));
        $eventAudit = AuditLog::query()->where('action', 'event.updated')->sole();
        $this->assertSame($round->id, data_get($eventAudit->metadata, 'before.round_id'));
        $this->assertSame($targetRound->id, data_get($eventAudit->metadata, 'after.round_id'));
        $this->assertSame($manager->id, $eventAudit->user_id);
    }

    public function test_invalid_rebuilt_options_roll_back_the_entire_event_update_without_an_audit(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $event = $this->draftEvent($round, $manager);
        $option = EventOption::factory()->for($event)->create([
            'label' => 'ConservÃ©e',
            'value' => 'conservee:1',
        ]);

        $this->assertValidationError('eventForm.custom_options', fn () => app(UpdateEvent::class)->handle(
            $pool,
            $event,
            $manager,
            $this->eventData($round, [
                'name' => 'Ne doit pas persister',
                'custom_options' => [],
            ]),
        ));

        $this->assertSame($event->name, $event->fresh()->name);
        $this->assertModelExists($option);
        $this->assertFalse(AuditLog::query()->where('action', 'event.updated')->exists());
    }

    public function test_editing_an_event_preserves_inactive_houseguest_options(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $activeHouseguest = Houseguest::factory()->for($pool->season)->create(['is_active' => true]);
        $inactiveHouseguest = Houseguest::factory()->for($pool->season)->create(['is_active' => false]);
        $event = app(CreateEvent::class)->handle($pool, $manager, $this->eventData($round, [
            'answer_source' => AnswerSource::Houseguests->value,
            'include_inactive_houseguests' => true,
        ]));

        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->call('startEventEdit', $event->id)
            ->assertSet('eventForm.include_inactive_houseguests', true)
            ->set('eventForm.name', 'Question mise a jour')
            ->call('updateEvent')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$activeHouseguest->id, $inactiveHouseguest->id],
            $event->fresh('options')->options->pluck('houseguest_id')->filter()->all(),
        );
    }

    public function test_the_custom_options_validation_error_is_visible_in_the_event_modal(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $event = $this->draftEvent($round, $manager);
        EventOption::factory()->for($event)->create([
            'label' => 'Option existante',
            'value' => 'option:1',
        ]);

        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->call('startEventEdit', $event->id)
            ->set('customOptionsText', '')
            ->call('updateEvent')
            ->assertHasErrors(['eventForm.custom_options'])
            ->assertSee(__('At least one option is required.'));

        $this->assertModelExists($event);
    }

    public function test_a_pool_manager_can_delete_unused_draft_events_and_rounds_with_audits(): void
    {
        [$manager, $pool] = $this->managedPool();
        $eventRound = Round::factory()->for($pool)->create(['position' => 1]);
        $event = $this->draftEvent($eventRound, $manager);
        $option = EventOption::factory()->for($event)->create();

        app(DeleteEvent::class)->handle($pool, $event, $manager);

        $this->assertModelMissing($event);
        $this->assertModelMissing($option);
        $eventAudit = AuditLog::query()->where('action', 'event.deleted')->sole();
        $this->assertSame($event->name, data_get($eventAudit->metadata, 'before.name'));
        $this->assertNull(data_get($eventAudit->metadata, 'after'));

        $round = Round::factory()->for($pool)->create(['position' => 2]);
        $roundEvent = $this->draftEvent($round, $manager);
        app(DeleteRound::class)->handle($pool, $round, $manager);

        $this->assertModelMissing($round);
        $this->assertModelMissing($roundEvent);
        $roundAudit = AuditLog::query()->where('action', 'round.deleted')->sole();
        $this->assertSame([$roundEvent->id], data_get($roundAudit->metadata, 'before.event_ids'));
    }

    public function test_event_deletion_rejects_opened_or_dependent_events_without_mutation_or_audit(): void
    {
        foreach (['opened', 'prediction', 'result', 'points', 'projection'] as $dependency) {
            [$manager, $pool, $member] = $this->managedPool();
            $round = Round::factory()->for($pool)->create();
            $event = $this->draftEvent($round, $manager);

            match ($dependency) {
                'opened' => $event->update([
                    'status' => EventStatus::Open,
                    'opens_at' => now()->subHour(),
                ]),
                'prediction' => EventPrediction::factory()
                    ->for($event)
                    ->for($member, 'poolMember')
                    ->create(),
                'result' => EventResult::factory()
                    ->for($event)
                    ->for($manager, 'createdBy')
                    ->create(),
                'points' => $this->createPointEntry($event, $manager, $member),
                'projection' => PoolEvent::factory()->create([
                    'pool_id' => $pool->id,
                    'season_event_id' => null,
                    'local_event_id' => $event->id,
                    'mode' => EventMode::Prediction,
                ]),
            };

            $this->assertValidationError('eventDeletion', fn () => app(DeleteEvent::class)->handle(
                $pool,
                $event->fresh(),
                $manager,
            ));

            $this->assertModelExists($event);
            $this->assertFalse(AuditLog::query()
                ->where('action', 'event.deleted')
                ->where('auditable_id', $event->id)
                ->exists());
        }
    }

    public function test_round_deletion_is_atomic_when_one_child_event_is_protected(): void
    {
        [$manager, $pool, $member] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $safeEvent = $this->draftEvent($round, $manager, 1);
        $protectedEvent = $this->draftEvent($round, $manager, 2);
        EventPrediction::factory()
            ->for($protectedEvent)
            ->for($member, 'poolMember')
            ->create();

        $this->assertValidationError('roundDeletion', fn () => app(DeleteRound::class)->handle(
            $pool,
            $round,
            $manager,
        ));

        $this->assertModelExists($round);
        $this->assertModelExists($safeEvent);
        $this->assertModelExists($protectedEvent);
        $this->assertFalse(AuditLog::query()->where('action', 'round.deleted')->exists());
    }

    public function test_actions_authorize_the_manager_and_scope_models_to_the_given_pool(): void
    {
        [$manager, $pool] = $this->managedPool();
        [$otherManager, $otherPool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $foreignRound = Round::factory()->for($otherPool)->create();
        $event = $this->draftEvent($round, $manager);

        try {
            app(UpdateRound::class)->handle($pool, $foreignRound, $manager, [
                'name' => 'Intrusion',
                'starts_at' => null,
                'ends_at' => null,
            ]);
            $this->fail('A foreign round should not be accepted by the scoped action.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(UpdateEvent::class)->handle($pool, $event, $otherManager, $this->eventData($round));
            $this->fail('A non-manager should not be able to update a local event.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($foreignRound->name, $foreignRound->fresh()->name);
        $this->assertSame($event->name, $event->fresh()->name);
        $this->assertFalse(AuditLog::query()->whereIn('action', ['round.updated', 'event.updated'])->exists());
    }

    /** @return array{User, Pool, PoolMember} */
    private function managedPool(): array
    {
        $manager = User::factory()->create();
        $pool = Pool::factory()->for($manager, 'owner')->create();
        $member = PoolMember::factory()->for($pool)->for($manager)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);

        return [$manager, $pool, $member];
    }

    private function draftEvent(Round $round, User $creator, int $position = 1): Event
    {
        return Event::factory()->for($round)->create([
            'pool_id' => $round->pool_id,
            'created_by' => $creator->id,
            'status' => EventStatus::Draft,
            'position' => $position,
            'opens_at' => null,
            'locks_at' => now()->addDays(2),
            'answer_source' => AnswerSource::Custom,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventData(Round $round, array $overrides = []): array
    {
        return [
            'round_id' => $round->id,
            'event_type_id' => null,
            'name' => 'Question locale',
            'question' => 'Quelle est la rÃ©ponse?',
            'mode' => EventMode::Prediction->value,
            'answer_source' => AnswerSource::Custom->value,
            'opens_at' => null,
            'locks_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
            'result_publication_mode' => 'immediate',
            'allow_none' => false,
            'include_inactive_houseguests' => false,
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => [
                    'points_per_correct' => 2,
                    'exact_match_bonus' => 0,
                    'wrong_answer_penalty' => 0,
                ],
                'allow_negative' => false,
            ],
            'custom_options' => ['Option A', 'Option B'],
            ...$overrides,
        ];
    }

    private function createPointEntry(Event $event, User $manager, PoolMember $member): PointEntry
    {
        $result = EventResult::factory()
            ->for($event)
            ->for($manager, 'createdBy')
            ->create();

        return PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'event_id' => $event->id,
            'pool_event_id' => null,
            'event_result_id' => $result->id,
            'season_event_result_id' => null,
            'event_prediction_id' => null,
            'pool_event_prediction_id' => null,
            'type' => 'prediction',
            'points' => 1,
            'reason' => 'Test local structure guard',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    private function assertValidationError(string $key, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected validation error [{$key}].");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
