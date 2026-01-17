<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Livewire\Livewire;

it('deactivates evicted houseguests when an outcome is saved', function () {
    $admin = User::factory()->admin()->create();
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create([
        'number' => 1,
        'evicted_count' => 1,
    ]);

    $boss = Houseguest::factory()->for($season)->create(['is_active' => true]);
    $evicted = Houseguest::factory()->for($season)->create(['is_active' => true]);

    $this->actingAs($admin);

    Livewire::test('admin.weeks.outcome', ['week' => $week])
        ->set('form.boss_houseguest_ids.0', $boss->id)
        ->set('form.evicted_houseguest_ids.0', $evicted->id)
        ->call('save')
        ->assertDispatched('outcome-saved');

    expect($evicted->refresh()->is_active)->toBeFalse();
});
