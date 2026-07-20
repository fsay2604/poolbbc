<?php

namespace Tests\Feature;

use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.seasons.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.official-rounds'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.official-results'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.houseguests.index'))->assertForbidden();
    }

    public function test_admin_users_can_access_admin_routes(): void
    {
        $administrator = User::factory()->admin()->create();

        $this->actingAs($administrator)->get(route('admin.seasons.index'))->assertSuccessful();
        $this->actingAs($administrator)->get(route('admin.official-rounds'))->assertSuccessful();
        $this->actingAs($administrator)->get(route('admin.official-results'))->assertSuccessful();
        $this->actingAs($administrator)->get(route('admin.houseguests.index'))->assertSuccessful();
    }

    public function test_official_administration_is_available_in_the_pool_context_only_to_administrators(): void
    {
        $administrator = User::factory()->admin()->create();
        $pool = Pool::factory()->create(['owner_id' => $administrator->id]);
        PoolMember::factory()->for($pool)->for($administrator)->create();

        $this->actingAs($administrator)
            ->get(route('pools.official-rounds', $pool))
            ->assertSuccessful()
            ->assertSee($pool->name)
            ->assertSee($pool->season->name)
            ->assertSee('Résultats officiels');

        $this->actingAs($administrator)
            ->get(route('pools.official-results', $pool))
            ->assertSuccessful()
            ->assertSee($pool->name)
            ->assertSee($pool->season->name)
            ->assertSee('Rondes et événements');

        $member = User::factory()->create();
        PoolMember::factory()->for($pool)->for($member)->create(['draft_position' => 2]);

        $this->actingAs($member)->get(route('pools.official-rounds', $pool))->assertForbidden();
        $this->actingAs($member)->get(route('pools.official-results', $pool))->assertForbidden();
    }

    public function test_legacy_routes_are_absent_and_canonical_routes_remain_registered(): void
    {
        foreach ([
            'weeks.index',
            'weeks.show',
            'season.prediction',
            'predictions.show',
            'current-week',
            'leaderboard',
            'admin.seasons.outcome',
            'admin.weeks.index',
            'admin.weeks.outcome',
            'admin.predictions.edit',
        ] as $legacyRoute) {
            $this->assertFalse(Route::has($legacyRoute));
        }

        foreach ([
            'dashboard',
            'pools.index',
            'pools.show',
            'pools.draft',
            'pools.predictions',
            'pools.events',
            'pools.leaderboard',
            'pools.official-rounds',
            'pools.official-results',
            'admin.seasons.index',
            'admin.event-types',
            'admin.official-rounds',
            'admin.official-results',
            'admin.houseguests.index',
            'admin.users.index',
        ] as $canonicalRoute) {
            $this->assertTrue(Route::has($canonicalRoute));
        }
    }
}
