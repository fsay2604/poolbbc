<?php

namespace Tests\Feature;

use App\Models\Houseguest;
use App\Models\Pool;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonEventResult;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDeleteSeasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_season_and_its_canonical_event_data(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $houseguest = Houseguest::factory()->for($season)->create();
        $round = SeasonRound::factory()->for($season)->create(['position' => 1]);
        $event = SeasonEvent::factory()
            ->for($round, 'round')
            ->for($administrator, 'creator')
            ->create(['position' => 1]);
        $option = SeasonEventOption::factory()->for($event, 'event')->create([
            'houseguest_id' => $houseguest->id,
        ]);
        Livewire::actingAs($administrator)
            ->test('admin.seasons.index')
            ->call('delete', $season->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($season);
        $this->assertModelMissing($houseguest);
        $this->assertModelMissing($round);
        $this->assertModelMissing($event);
        $this->assertModelMissing($option);
    }

    public function test_admin_cannot_delete_a_season_with_official_result_history(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()
            ->for($round, 'round')
            ->for($administrator, 'creator')
            ->create();
        $result = SeasonEventResult::factory()
            ->for($event, 'event')
            ->for($administrator, 'creator')
            ->create();

        Livewire::actingAs($administrator)
            ->test('admin.seasons.index')
            ->call('delete', $season->id)
            ->assertHasErrors(['seasonDeletion']);

        $this->assertModelExists($season);
        $this->assertModelExists($result);
    }

    public function test_admin_cannot_delete_a_season_with_a_pool(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()
            ->for($season)
            ->for($administrator, 'owner')
            ->create();

        Livewire::actingAs($administrator)
            ->test('admin.seasons.index')
            ->call('delete', $season->id)
            ->assertHasErrors(['seasonDeletion']);

        $this->assertModelExists($season);
        $this->assertModelExists($pool);
    }
}
