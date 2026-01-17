<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Livewire\Livewire;

test('admin can set season outcome for the active season', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('admin.seasons.outcome')
        ->set('form.winner_houseguest_id', $houseguests[0]->id)
        ->set('form.first_evicted_houseguest_id', $houseguests[1]->id)
        ->set('form.top_6_1_houseguest_id', $houseguests[0]->id)
        ->set('form.top_6_2_houseguest_id', $houseguests[2]->id)
        ->set('form.top_6_3_houseguest_id', $houseguests[3]->id)
        ->set('form.top_6_4_houseguest_id', $houseguests[4]->id)
        ->set('form.top_6_5_houseguest_id', $houseguests[5]->id)
        ->set('form.top_6_6_houseguest_id', $houseguests[6]->id)
        ->call('save')
        ->assertHasNoErrors();

    $season->refresh();

    expect($season->winner_houseguest_id)->toBe($houseguests[0]->id);
    expect($season->first_evicted_houseguest_id)->toBe($houseguests[1]->id);
    expect($season->top_6_houseguest_ids)->toBe([
        $houseguests[0]->id,
        $houseguests[2]->id,
        $houseguests[3]->id,
        $houseguests[4]->id,
        $houseguests[5]->id,
        $houseguests[6]->id,
    ]);
});

test('admin season outcome keeps inactive selections visible', function () {
    $season = Season::factory()->create([
        'is_active' => true,
        'winner_houseguest_id' => null,
        'first_evicted_houseguest_id' => null,
        'top_6_houseguest_ids' => [],
    ]);

    $inactiveSelected = Houseguest::factory()->for($season)->create([
        'is_active' => false,
        'name' => 'Evicted Player',
    ]);

    Houseguest::factory()->for($season)->create([
        'is_active' => true,
        'name' => 'Active Player',
    ]);

    $season->forceFill([
        'winner_houseguest_id' => $inactiveSelected->id,
    ])->save();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('admin.seasons.outcome')
        ->assertSee('Evicted Player')
        ->assertSee('Active Player');
});
