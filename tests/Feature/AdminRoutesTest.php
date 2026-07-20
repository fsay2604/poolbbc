<?php

namespace Tests\Feature;

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
