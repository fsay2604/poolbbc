<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class WeeklyPredictionPhasesIndependentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_confirm_a_prediction_where_hoh_is_also_a_nominee_veto_winner_and_evicted(): void
    {
        Carbon::setTestNow('2026-01-04 12:00:00');

        $user = User::factory()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $week = Week::factory()->for($season)->create([
            'is_locked' => false,
            'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
        ]);

        $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $hg2 = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $hg4 = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $hg5 = Houseguest::factory()->for($season)->create(['is_active' => true]);

        $this->actingAs($user);

        $response = Livewire::test('weeks.show', ['week' => $week])
            ->set('form.phases.0.hoh_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.1', $hg2->id)
            ->set('form.phases.2.winner_ids.0', $boss->id)
            ->set('form.phases.2.veto_used', true)
            ->set('form.phases.2.saved_ids.0', $hg4->id)
            ->set('form.phases.2.replacement_ids.0', $hg5->id)
            ->set('form.phases.3.evicted_ids.0', $boss->id)
            ->call('confirm');

        $response->assertHasNoErrors();

        $prediction = Prediction::query()
            ->where('week_id', $week->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertIsArray($prediction->phase_picks);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'hoh')['hoh_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'nominees')['nominee_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'veto')['winner_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'evictions')['evicted_ids'][0] ?? null);
        $this->assertNotNull($prediction->confirmed_at);
    }

    public function test_admin_can_save_an_outcome_where_hoh_is_also_veto_winner_and_evicted(): void
    {
        Carbon::setTestNow('2026-01-04 12:00:00');

        $admin = User::factory()->admin()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $week = Week::factory()->for($season)->create([
            'is_locked' => false,
            'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
        ]);

        $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $other = Houseguest::factory()->for($season)->create(['is_active' => true]);

        $this->actingAs($admin);

        $response = Livewire::test('admin.weeks.outcome', ['week' => $week])
            ->set('form.phases.0.hoh_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.1', $other->id)
            ->set('form.phases.2.winner_ids.0', $boss->id)
            ->set('form.phases.2.veto_used', true)
            ->set('form.phases.2.saved_ids.0', $other->id)
            ->set('form.phases.2.replacement_ids.0', $other->id)
            ->set('form.phases.3.evicted_ids.0', $boss->id)
            ->call('save');

        $response->assertHasNoErrors();
    }

    public function test_admin_can_save_a_prediction_where_hoh_is_also_evicted(): void
    {
        Carbon::setTestNow('2026-01-04 12:00:00');

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $week = Week::factory()->for($season)->create([
            'is_locked' => false,
            'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
        ]);

        $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $other = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $hg3 = Houseguest::factory()->for($season)->create(['is_active' => true]);

        $prediction = Prediction::factory()->for($week)->for($user)->create([
            'phase_picks' => [
                [
                    'phase_id' => $week->phases()->where('type', 'hoh')->value('id'),
                    'position' => 1,
                    'type' => 'hoh',
                    'hoh_ids' => [$boss->id],
                ],
                [
                    'phase_id' => $week->phases()->where('type', 'nominees')->value('id'),
                    'position' => 2,
                    'type' => 'nominees',
                    'nominee_ids' => [$boss->id, $other->id],
                ],
                [
                    'phase_id' => $week->phases()->where('type', 'veto')->value('id'),
                    'position' => 3,
                    'type' => 'veto',
                    'veto_used' => false,
                    'winner_ids' => [],
                    'saved_ids' => [],
                    'replacement_ids' => [],
                ],
                [
                    'phase_id' => $week->phases()->where('type', 'evictions')->value('id'),
                    'position' => 4,
                    'type' => 'evictions',
                    'evicted_ids' => [$other->id],
                ],
            ],
        ]);

        $this->actingAs($admin);

        $response = Livewire::test('admin.predictions.edit', ['prediction' => $prediction])
            ->set('form.phases.0.hoh_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.1', $other->id)
            ->set('form.phases.2.winner_ids.0', $boss->id)
            ->set('form.phases.2.veto_used', true)
            ->set('form.phases.2.saved_ids.0', $other->id)
            ->set('form.phases.2.replacement_ids.0', $hg3->id)
            ->set('form.phases.3.evicted_ids.0', $boss->id)
            ->call('save');

        $response->assertHasNoErrors();

        $prediction->refresh();

        $this->assertIsArray($prediction->phase_picks);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'hoh')['hoh_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'nominees')['nominee_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'veto')['winner_ids'][0] ?? null);
        $this->assertSame($boss->id, collect($prediction->phase_picks)->firstWhere('type', 'evictions')['evicted_ids'][0] ?? null);
    }

    public function test_user_can_confirm_when_veto_switch_posts_on_string(): void
    {
        Carbon::setTestNow('2026-01-04 12:00:00');

        $user = User::factory()->create();
        $season = Season::factory()->create(['is_active' => true]);
        $week = Week::factory()->for($season)->create([
            'is_locked' => false,
            'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
        ]);

        $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $nominee1 = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $nominee2 = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $vetoWinner = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $saved = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $replacement = Houseguest::factory()->for($season)->create(['is_active' => true]);
        $evicted = Houseguest::factory()->for($season)->create(['is_active' => true]);

        $this->actingAs($user);

        $response = Livewire::test('weeks.show', ['week' => $week])
            ->set('form.phases.0.hoh_ids.0', $boss->id)
            ->set('form.phases.1.nominee_ids.0', $nominee1->id)
            ->set('form.phases.1.nominee_ids.1', $nominee2->id)
            ->set('form.phases.2.winner_ids.0', $vetoWinner->id)
            ->set('form.phases.2.veto_used', 'on')
            ->set('form.phases.2.saved_ids.0', $saved->id)
            ->set('form.phases.2.replacement_ids.0', $replacement->id)
            ->set('form.phases.3.evicted_ids.0', $evicted->id)
            ->call('confirm');

        $response->assertHasNoErrors();

        $prediction = Prediction::query()
            ->where('week_id', $week->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertTrue((bool) (collect($prediction->phase_picks)->firstWhere('type', 'veto')['veto_used'] ?? false));
    }
}
