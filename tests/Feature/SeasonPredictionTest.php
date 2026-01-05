<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use Livewire\Volt\Volt;

test('user can save a draft season prediction', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $winner = $houseguests[0];

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->set('form.winner_houseguest_id', $winner->id)
        ->call('save')
        ->assertHasNoErrors();

    $prediction = SeasonPrediction::query()
        ->where('season_id', $season->id)
        ->where('user_id', $user->id)
        ->first();

    expect($prediction)->not->toBeNull();
    expect($prediction->winner_houseguest_id)->toBe($winner->id);
    expect($prediction->confirmed_at)->toBeNull();
});

test('user can confirm and lock season predictions', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $winner = $houseguests[0];
    $firstEvicted = $houseguests[1];
    $top6 = $houseguests->slice(2, 6)->pluck('id')->all();

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->set('form.winner_houseguest_id', $winner->id)
        ->set('form.first_evicted_houseguest_id', $firstEvicted->id)
        ->set('form.top_6_1_houseguest_id', $top6[0])
        ->set('form.top_6_2_houseguest_id', $top6[1])
        ->set('form.top_6_3_houseguest_id', $top6[2])
        ->set('form.top_6_4_houseguest_id', $top6[3])
        ->set('form.top_6_5_houseguest_id', $top6[4])
        ->set('form.top_6_6_houseguest_id', $top6[5])
        ->call('confirm')
        ->assertHasNoErrors();

    $prediction = SeasonPrediction::query()
        ->where('season_id', $season->id)
        ->where('user_id', $user->id)
        ->first();

    expect($prediction)->not->toBeNull();
    expect($prediction->winner_houseguest_id)->toBe($winner->id);
    expect($prediction->first_evicted_houseguest_id)->toBe($firstEvicted->id);
    expect($prediction->top_6_houseguest_ids)->toBe($top6);
    expect($prediction->confirmed_at)->not->toBeNull();

    Volt::test('season-prediction')
        ->call('save')
        ->assertStatus(403);
});

test('user cannot pick the same winner and first evicted when confirming', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $same = $houseguests[0];
    $top6 = $houseguests->slice(2, 6)->pluck('id')->all();

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->set('form.winner_houseguest_id', $same->id)
        ->set('form.first_evicted_houseguest_id', $same->id)
        ->set('form.top_6_1_houseguest_id', $top6[0])
        ->set('form.top_6_2_houseguest_id', $top6[1])
        ->set('form.top_6_3_houseguest_id', $top6[2])
        ->set('form.top_6_4_houseguest_id', $top6[3])
        ->set('form.top_6_5_houseguest_id', $top6[4])
        ->set('form.top_6_6_houseguest_id', $top6[5])
        ->call('confirm')
        ->assertHasErrors([
            'form.winner_houseguest_id',
            'form.first_evicted_houseguest_id',
        ]);
});

test('user cannot pick duplicate top 6 entries when confirming', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $houseguests = Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $winner = $houseguests[0];
    $firstEvicted = $houseguests[1];
    $top6 = $houseguests->slice(2, 6)->pluck('id')->all();

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->set('form.winner_houseguest_id', $winner->id)
        ->set('form.first_evicted_houseguest_id', $firstEvicted->id)
        ->set('form.top_6_1_houseguest_id', $top6[0])
        ->set('form.top_6_2_houseguest_id', $top6[0])
        ->set('form.top_6_3_houseguest_id', $top6[2])
        ->set('form.top_6_4_houseguest_id', $top6[3])
        ->set('form.top_6_5_houseguest_id', $top6[4])
        ->set('form.top_6_6_houseguest_id', $top6[5])
        ->call('confirm')
        ->assertHasErrors(['form.top_6_2_houseguest_id']);
});

test('user cannot confirm when required fields are missing', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    Houseguest::factory()
        ->for($season)
        ->count(8)
        ->create(['is_active' => true]);

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->call('confirm')
        ->assertHasErrors([
            'form.winner_houseguest_id',
            'form.first_evicted_houseguest_id',
            'form.top_6_1_houseguest_id',
            'form.top_6_2_houseguest_id',
            'form.top_6_3_houseguest_id',
            'form.top_6_4_houseguest_id',
            'form.top_6_5_houseguest_id',
            'form.top_6_6_houseguest_id',
        ]);
});

test('locked season predictions keep inactive selections visible', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_active' => true]);

    $inactiveSelected = Houseguest::factory()->for($season)->create([
        'is_active' => false,
        'name' => 'Evicted Player',
    ]);

    Houseguest::factory()->for($season)->create([
        'is_active' => true,
        'name' => 'Active Player',
    ]);

    $top6 = Houseguest::factory()->for($season)->count(6)->create(['is_active' => true])->pluck('id')->all();

    SeasonPrediction::factory()->for($season)->for($user)->create([
        'winner_houseguest_id' => $inactiveSelected->id,
        'first_evicted_houseguest_id' => $top6[0],
        'top_6_houseguest_ids' => $top6,
        'confirmed_at' => now(),
    ]);

    $this->actingAs($user);

    Volt::test('season-prediction')
        ->assertSee('Evicted Player')
        ->assertSee('Active Player');
});
