<?php

namespace Tests\Feature;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolStatus;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PoolStructureAndResultsNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pool_navigation_separates_member_predictions_from_management_pages(): void
    {
        [$pool, $owner] = $this->poolContext();
        $member = PoolMember::factory()->for($pool)->create(['draft_position' => 2]);

        $this->actingAs($member->user)
            ->get(route('pools.show', $pool))
            ->assertOk()
            ->assertSee('Prédictions')
            ->assertDontSee('Rondes et événements')
            ->assertDontSee('Résultats');

        $this->actingAs($owner)
            ->get(route('pools.show', $pool))
            ->assertOk()
            ->assertSee('Prédictions')
            ->assertSee('Rondes et événements')
            ->assertSee('Résultats')
            ->assertDontSee('Événements du pool')
            ->assertDontSee('Résultats officiels');
    }

    public function test_structure_page_contains_management_only_and_results_page_contains_results_only(): void
    {
        [$pool, $owner, $round, $event] = $this->poolContext();
        EventOption::factory()->for($event)->create(['label' => 'Alex', 'position' => 1]);
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);

        Livewire::actingAs($owner)
            ->test('pools.events', ['pool' => $pool])
            ->assertSee('Structure propre au pool')
            ->assertSee($round->name)
            ->assertSee($event->name)
            ->assertSee('Nouvelle ronde')
            ->assertDontSee('Réponses finales')
            ->assertDontSee('Aperçu des points');

        Livewire::actingAs($owner)
            ->test('pools.results', ['pool' => $pool])
            ->assertSee('Résultats propres au pool')
            ->assertSee($event->name)
            ->assertSee('Réponses finales')
            ->assertDontSee('Nouvelle ronde')
            ->assertDontSee('Participation · réponses masquées');
    }

    public function test_local_result_can_be_previewed_and_published_from_the_results_page(): void
    {
        [$pool, $owner, , $event] = $this->poolContext();
        $option = EventOption::factory()->for($event)->create(['label' => 'Réponse finale', 'position' => 1]);
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDay(),
            'locks_at' => now()->subHour(),
        ]);

        Livewire::actingAs($owner)
            ->test('pools.results', ['pool' => $pool])
            ->set("resultSelections.{$event->id}", [$option->id])
            ->call('previewResult', $event->id)
            ->assertHasNoErrors()
            ->call('publishResult', $event->id)
            ->assertHasNoErrors()
            ->assertDispatched('result-published');

        $this->assertDatabaseHas('event_results', [
            'event_id' => $event->id,
            'status' => 'published',
            'version' => 1,
        ]);
    }

    public function test_contextual_official_routes_redirect_to_the_unified_pool_pages(): void
    {
        [$pool] = $this->poolContext();
        $administrator = User::factory()->admin()->create();
        PoolMember::factory()->for($pool)->for($administrator)->create([
            'role' => PoolMemberRole::Administrator,
            'draft_position' => 2,
        ]);

        $this->actingAs($administrator)
            ->get(route('pools.official-rounds', $pool))
            ->assertRedirect(route('pools.events', $pool));

        $this->actingAs($administrator)
            ->get(route('pools.official-results', $pool))
            ->assertRedirect(route('pools.results', $pool));
    }

    public function test_member_cannot_open_management_pages_directly(): void
    {
        [$pool] = $this->poolContext();
        $member = PoolMember::factory()->for($pool)->create(['draft_position' => 2]);

        $this->actingAs($member->user)->get(route('pools.events', $pool))->assertForbidden();
        $this->actingAs($member->user)->get(route('pools.results', $pool))->assertForbidden();
    }

    /** @return array{Pool, User, Round, Event} */
    private function poolContext(): array
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        SeasonRound::factory()->for($season)->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);
        $round = Round::factory()->for($pool)->create(['name' => 'Ronde locale']);
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'name' => 'Événement local',
            'mode' => EventMode::Prediction,
            'status' => EventStatus::Draft,
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'result_min_selections' => 1,
            'result_max_selections' => 1,
        ]);

        return [$pool, $owner, $round, $event];
    }
}
