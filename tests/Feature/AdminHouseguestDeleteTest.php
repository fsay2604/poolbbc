<?php

declare(strict_types=1);

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('admin can delete a houseguest and its avatar', function () {
    Storage::fake('public');
    Cache::spy();

    $season = Season::factory()->create(['is_active' => true]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $avatarPath = 'houseguests/avatars/houseguest-delete.png';
    Storage::disk('public')->put($avatarPath, 'avatar');

    $houseguest = Houseguest::factory()->for($season)->create([
        'avatar_url' => $avatarPath,
    ]);

    Livewire::test('admin.houseguests.index')
        ->call('confirmDelete', $houseguest->id)
        ->call('deleteSelectedHouseguest')
        ->assertDispatched('houseguest-deleted');

    expect(Houseguest::query()->whereKey($houseguest->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($avatarPath);
    Cache::shouldHaveReceived('forget')->with("dashboard.stats.season.{$season->id}");
});
