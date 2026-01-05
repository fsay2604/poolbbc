<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;

it('renders an empty option so the first houseguest is selectable', function () {
    $user = User::factory()->create();

    $season = Season::factory()->create([
        'is_active' => true,
    ]);

    $hg1 = Houseguest::factory()->create([
        'season_id' => $season->id,
        'name' => 'HG 1',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    Houseguest::factory()->create([
        'season_id' => $season->id,
        'name' => 'HG 2',
        'sort_order' => 2,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('season.prediction'))
        ->assertOk()
        ->assertSee('<option value="">—</option>', false)
        ->assertSee('value="'.$hg1->id.'">HG 1</option>', false);
});
