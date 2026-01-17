<?php

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('user cannot confirm a prediction where HOH is also a nominee/veto winner/evicted', function () {
    Carbon::setTestNow('2026-01-04 12:00:00');

    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create([
        'is_locked' => false,
        'auto_lock_at' => Carbon::parse('2026-01-10 19:00:00'),
    ]);

    $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $hg2 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $hg3 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $hg4 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $hg5 = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $hg6 = Houseguest::factory()->for($season)->create(['is_active' => true]);

    $this->actingAs($user);

    $response = Livewire::test('weeks.show', ['week' => $week])
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.nominee_houseguest_ids.0', $boss->id)
        ->set('form.nominee_houseguest_ids.1', $hg2->id)
        ->set('form.veto_winner_houseguest_id', $hg3->id)
        ->set('form.veto_used', true)
        ->set('form.saved_houseguest_id', $hg4->id)
        ->set('form.replacement_nominee_houseguest_id', $hg5->id)
        ->set('form.evicted_houseguest_ids.0', $hg6->id)
        ->call('confirm');

    $response->assertHasErrors(['form.boss_houseguest_ids']);
});

test('admin cannot save an outcome where HOH is also veto winner or evicted', function () {
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
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.veto_winner_houseguest_id', $boss->id)
        ->set('form.evicted_houseguest_ids.0', $other->id)
        ->call('save');

    $response->assertHasErrors(['form.boss_houseguest_ids']);
});

test('admin cannot save a prediction where HOH is also evicted', function () {
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

    $prediction = Prediction::factory()->for($week)->for($user)->create([
        'hoh_houseguest_id' => $boss->id,
        'evicted_houseguest_id' => $other->id,
    ]);

    $this->actingAs($admin);

    $response = Livewire::test('admin.predictions.edit', ['prediction' => $prediction])
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.evicted_houseguest_ids.0', $boss->id)
        ->call('save');

    $response->assertHasErrors(['form.boss_houseguest_ids']);
});
