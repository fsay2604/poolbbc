<?php

declare(strict_types=1);

use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;

test('weeks table does not show prediction confirmation status', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $week1 = Week::factory()->for($season)->create(['number' => 1]);
    $week2 = Week::factory()->for($season)->create(['number' => 2]);

    Prediction::factory()->for($week1)->for($user)->create(['confirmed_at' => now()]);
    Prediction::factory()->for($week2)->for($user)->create(['confirmed_at' => null]);

    $this->actingAs($user)
        ->get('/weeks')
        ->assertSuccessful()
        ->assertSee(__('Status'))
        ->assertDontSee(__('Confirmation status'))
        ->assertDontSee(__('Confirmed'))
        ->assertDontSee(__('Pending'));
});
