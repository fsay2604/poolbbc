<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;

test('dashboard shows a card for each houseguest and sepia for inactive avatars', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $hg1 = Houseguest::factory()->for($season)->create([
        'name' => 'Player One',
        'is_active' => true,
        'avatar_url' => 'houseguests/avatars/player-one.png',
    ]);

    $hg2 = Houseguest::factory()->for($season)->create([
        'name' => 'Player Two',
        'is_active' => true,
        'avatar_url' => null,
    ]);

    $hg3 = Houseguest::factory()->for($season)->create([
        'name' => 'Player Three',
        'is_active' => false,
        'avatar_url' => null,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertSee($season->name)
        ->assertSee($hg1->name)
        ->assertSee($hg2->name)
        ->assertSee($hg3->name)
        ->assertSee('storage/'.$hg1->avatar_url);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('filter grayscale');
});
