<?php

namespace Tests\Feature;

use App\Models\Prediction;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Models\Week;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LegacyPredictionPrivacyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_opponents_and_participating_admins_never_hydrate_predictions_before_lock(): void
    {
        $season = Season::factory()->create(['is_active' => true, 'prediction_locks_at' => now()->addHour()]);
        $week = Week::factory()->for($season)->create(['is_locked' => false, 'auto_lock_at' => now()->addHour()]);
        $subject = User::factory()->create();
        $opponent = User::factory()->create();
        $admin = User::factory()->admin()->create();

        Prediction::factory()->submitted()->for($week)->for($subject)->create();
        SeasonPrediction::factory()->submitted()->for($season)->for($subject)->create();

        foreach ([$opponent, $admin] as $viewer) {
            Livewire::actingAs($viewer)
                ->test('predictions.show', ['user' => $subject])
                ->assertSet('predictions', fn ($predictions): bool => $predictions->isEmpty())
                ->assertSet('seasonPrediction', null);
        }
    }

    public function test_owner_can_hydrate_own_draft_and_opponent_only_sees_submitted_predictions_after_lock(): void
    {
        $season = Season::factory()->create(['is_active' => true, 'prediction_locks_at' => now()->subSecond()]);
        $lockedWeek = Week::factory()->for($season)->create(['number' => 1, 'auto_lock_at' => now()->subSecond()]);
        $draftWeek = Week::factory()->for($season)->create(['number' => 2, 'auto_lock_at' => now()->subSecond()]);
        $subject = User::factory()->create();
        $opponent = User::factory()->create();

        $submitted = Prediction::factory()->submitted()->for($lockedWeek)->for($subject)->create();
        Prediction::factory()->for($draftWeek)->for($subject)->create();
        $seasonPrediction = SeasonPrediction::factory()->submitted()->for($season)->for($subject)->create();

        Livewire::actingAs($subject)
            ->test('predictions.show', ['user' => $subject])
            ->assertSet('predictions', fn ($predictions): bool => $predictions->count() === 2);

        Livewire::actingAs($opponent)
            ->test('predictions.show', ['user' => $subject])
            ->assertSet('predictions', fn ($predictions): bool => $predictions->count() === 1
                && $predictions->first()->is($submitted))
            ->assertSet('seasonPrediction.id', $seasonPrediction->id);
    }

    public function test_administrator_cannot_open_the_legacy_editor_before_the_prediction_locks(): void
    {
        $season = Season::factory()->create(['is_active' => true]);
        $week = Week::factory()->for($season)->create(['is_locked' => false, 'auto_lock_at' => now()->addHour()]);
        $prediction = Prediction::factory()->submitted()->for($week)->create();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('admin.predictions.edit', ['prediction' => $prediction])
            ->assertForbidden();
    }
}
