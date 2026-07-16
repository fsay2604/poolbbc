<?php

namespace Tests\Feature;

use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Actions\Pools\RemovePoolMember;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PoolFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_create_a_pool_and_another_user_can_join_with_the_code(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $season = Season::factory()->create();

        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Pool du dimanche',
            'description' => 'Entre amis',
            'timezone' => 'America/Toronto',
            'max_members' => 4,
            'picks_per_member' => 2,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $ownerMembership = $pool->members()->whereBelongsTo($owner)->firstOrFail();
        $this->assertSame(PoolMemberRole::Owner, $ownerMembership->role);
        $this->assertNotNull($pool->draft);
        $this->assertSame(8, mb_strlen($pool->invite_code));

        $membership = app(JoinPool::class)->handle($member, mb_strtolower($pool->invite_code));

        $this->assertSame($pool->id, $membership->pool_id);
        $this->assertSame(2, $membership->draft_position);
        $this->assertTrue(Gate::forUser($member)->allows('view', $pool));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $pool));
    }

    public function test_a_pool_rejects_members_after_reaching_capacity(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Pool complet',
            'description' => null,
            'timezone' => 'America/Toronto',
            'max_members' => 1,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
    }

    public function test_owner_can_remove_a_member_before_the_draft_and_positions_are_reordered(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Pool avec remplacement',
            'description' => null,
            'timezone' => 'America/Toronto',
            'max_members' => 4,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $removedMember = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $remainingMember = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);

        app(RemovePoolMember::class)->handle($pool, $removedMember, $owner);

        $this->assertSame(PoolMemberStatus::Removed, $removedMember->fresh()->status);
        $this->assertNull($removedMember->fresh()->draft_position);
        $this->assertSame(2, $remainingMember->fresh()->draft_position);
        $this->assertDatabaseHas('audit_logs', [
            'pool_id' => $pool->id,
            'user_id' => $owner->id,
            'action' => 'pool.member_removed',
        ]);
    }

    public function test_pool_livewire_flows_render_for_an_authorized_member(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $this->actingAs($owner);

        Livewire::test('pools.index')
            ->set('form.season_id', $season->id)
            ->set('form.name', 'Pool Livewire')
            ->call('create')
            ->assertHasNoErrors();

        $pool = $owner->ownedPools()->where('name', 'Pool Livewire')->firstOrFail();

        $this->get(route('pools.show', $pool))->assertOk();
        $this->get(route('pools.draft', $pool))->assertOk();
        $this->get(route('pools.events', $pool))->assertOk();
        $this->get(route('pools.leaderboard', $pool))->assertOk();
    }
}
