<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('admin can upload a houseguest avatar', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Storage::fake('public');

    Volt::test('admin.houseguests.index')
        ->set('form.name', 'Player One')
        ->set('avatar', UploadedFile::fake()->image('avatar.png'))
        ->set('form.is_active', true)
        ->set('form.sort_order', 1)
        ->call('save')
        ->assertHasNoErrors();

    $houseguest = Houseguest::query()->where('season_id', $season->id)->where('name', 'Player One')->first();

    expect($houseguest)->not->toBeNull();
    expect($houseguest->avatar_url)->not->toBeNull();

    Storage::disk('public')->assertExists($houseguest->avatar_url);
});

test('admin can set houseguest sex', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('admin.houseguests.index')
        ->set('form.name', 'Player Two')
        ->set('form.sex', 'F')
        ->set('form.is_active', true)
        ->set('form.sort_order', 2)
        ->call('save')
        ->assertHasNoErrors();

    $houseguest = Houseguest::query()
        ->where('season_id', $season->id)
        ->where('name', 'Player Two')
        ->first();

    expect($houseguest)->not->toBeNull();
    expect($houseguest->sex)->toBe('F');
});

test('admin can set multiple houseguest occupations', function () {
    $season = Season::factory()->create(['is_active' => true]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('admin.houseguests.index')
        ->set('form.name', 'Player Three')
        ->set('form.sex', 'M')
        ->set('form.occupations', 'Actor, Comedian')
        ->set('form.is_active', true)
        ->set('form.sort_order', 3)
        ->call('save')
        ->assertHasNoErrors();

    $houseguest = Houseguest::query()
        ->where('season_id', $season->id)
        ->where('name', 'Player Three')
        ->first();

    expect($houseguest)->not->toBeNull();
    expect($houseguest->occupations)->toBe(['Actor', 'Comedian']);
});
