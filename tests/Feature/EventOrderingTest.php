<?php

namespace Tests\Feature;

use App\Actions\Events\ReorderRoundEvents;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\EventResult;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EventOrderingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manager_can_reorder_draft_events_without_unique_position_collisions_and_the_change_is_audited(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');
        $third = $this->draftEvent($round, $manager, 3, 'Troisieme');

        $reorderedRound = app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$third->id, $first->id, $second->id],
        );

        $this->assertSame([$third->id, $first->id, $second->id], $reorderedRound->events->pluck('id')->all());
        $this->assertSame(
            [1, 2, 3],
            Event::query()->whereBelongsTo($round)->orderBy('position')->pluck('position')->all(),
        );

        $auditLog = AuditLog::query()->where('action', 'round.events_reordered')->firstOrFail();
        $this->assertSame($round->id, $auditLog->auditable_id);
        $this->assertSame([$first->id, $second->id, $third->id], $auditLog->metadata['previous_event_ids']);
        $this->assertSame([$third->id, $first->id, $second->id], $auditLog->metadata['event_ids']);
    }

    public function test_manager_can_move_an_event_with_accessible_livewire_controls(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');

        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->assertSeeHtml('aria-label="Monter Deuxieme"')
            ->assertSeeHtml('aria-label="Descendre Premier"')
            ->call('moveEvent', $second->id, 'up')
            ->assertHasNoErrors()
            ->assertDispatched('events-reordered');

        $this->assertSame(
            [$second->id, $first->id],
            Event::query()->whereBelongsTo($round)->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_event_order_rejects_events_from_another_round_or_pool(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create(['position' => 1]);
        $otherRound = Round::factory()->for($pool)->create(['position' => 2]);
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');
        $otherRoundEvent = $this->draftEvent($otherRound, $manager, 1, 'Autre ronde');

        [$otherManager, $otherPool] = $this->managedPool();
        $foreignRound = Round::factory()->for($otherPool)->create();
        $foreignEvent = $this->draftEvent($foreignRound, $otherManager, 1, 'Autre pool');

        $this->assertInvalidOrder(fn () => app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$second->id, $otherRoundEvent->id],
        ));
        $this->assertInvalidOrder(fn () => app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$second->id, $foreignEvent->id],
        ));

        $this->assertSame(
            [$first->id, $second->id],
            Event::query()->whereBelongsTo($round)->orderBy('position')->pluck('id')->all(),
        );
        $this->assertFalse(AuditLog::query()->where('action', 'round.events_reordered')->exists());
    }

    public function test_non_manager_cannot_reorder_events(): void
    {
        [$manager, $pool] = $this->managedPool();
        $member = User::factory()->create();
        PoolMember::factory()->for($pool)->for($member)->create([
            'role' => PoolMemberRole::Member,
            'draft_position' => 2,
        ]);
        $round = Round::factory()->for($pool)->create();
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');

        $this->expectException(AuthorizationException::class);

        app(ReorderRoundEvents::class)->handle($round, $member, [$second->id, $first->id]);
    }

    public function test_event_order_is_frozen_when_an_event_is_no_longer_a_draft(): void
    {
        [$manager, $pool] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');
        $second->update(['status' => EventStatus::Open]);

        $this->assertInvalidOrder(fn () => app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$second->id, $first->id],
        ));
    }

    public function test_event_order_is_frozen_after_a_response_or_result_exists(): void
    {
        [$manager, $pool, $managerMember] = $this->managedPool();
        $round = Round::factory()->for($pool)->create();
        $first = $this->draftEvent($round, $manager, 1, 'Premier');
        $second = $this->draftEvent($round, $manager, 2, 'Deuxieme');
        $prediction = EventPrediction::factory()
            ->for($first)
            ->for($managerMember, 'poolMember')
            ->create();

        $this->assertInvalidOrder(fn () => app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$second->id, $first->id],
        ));
        Livewire::actingAs($manager)
            ->test('pools.events', ['pool' => $pool])
            ->assertDontSeeHtml('aria-label="Monter Deuxieme"')
            ->assertDontSeeHtml('aria-label="Descendre Premier"');

        $prediction->delete();
        EventResult::factory()->for($first)->for($manager, 'createdBy')->create();

        $this->assertInvalidOrder(fn () => app(ReorderRoundEvents::class)->handle(
            $round,
            $manager,
            [$second->id, $first->id],
        ));
        $this->assertSame(
            [$first->id, $second->id],
            Event::query()->whereBelongsTo($round)->orderBy('position')->pluck('id')->all(),
        );
    }

    /** @return array{User, Pool, PoolMember} */
    private function managedPool(): array
    {
        $manager = User::factory()->create();
        $pool = Pool::factory()->for($manager, 'owner')->create();
        $managerMember = PoolMember::factory()->for($pool)->for($manager)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);

        return [$manager, $pool, $managerMember];
    }

    private function draftEvent(Round $round, User $creator, int $position, string $name): Event
    {
        return Event::factory()->for($round)->create([
            'pool_id' => $round->pool_id,
            'created_by' => $creator->id,
            'name' => $name,
            'status' => EventStatus::Draft,
            'position' => $position,
            'opens_at' => null,
        ]);
    }

    private function assertInvalidOrder(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected event order validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('events', $exception->errors());
        }
    }
}
