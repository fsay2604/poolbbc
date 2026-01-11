<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('admin can edit a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Original Name', 'email' => 'original@example.com']);

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('edit', $user->id)
        ->set('form.name', 'Updated Name')
        ->set('form.email', 'updated@example.com')
        ->set('form.is_admin', true)
        ->call('save');

    $user->refresh();

    expect($user->name)->toBe('Updated Name');
    expect($user->email)->toBe('updated@example.com');
    expect($user->is_admin)->toBeTrue();
});

test('admin can create a user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->set('form.name', 'Created User')
        ->set('form.email', 'created@example.com')
        ->set('form.is_admin', false)
        ->set('form.password', 'new-password-123')
        ->set('form.password_confirmation', 'new-password-123')
        ->call('save');

    $user = User::query()->where('email', 'created@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Created User');
    expect(Hash::check('new-password-123', $user->password))->toBeTrue();
});

test('admin can create a user with an avatar', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->set('form.name', 'Avatar User')
        ->set('form.email', 'avatar@example.com')
        ->set('form.is_admin', false)
        ->set('form.password', 'new-password-123')
        ->set('form.password_confirmation', 'new-password-123')
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg'))
        ->call('save');

    $user = User::query()->where('email', 'avatar@example.com')->firstOrFail();

    expect($user->avatar_url)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_url);
});

test('admin can toggle admin status', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('toggleAdmin', $user->id);

    $user->refresh();

    expect($user->is_admin)->toBeTrue();
});

test('admin can reset a user password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['password' => 'old-password']);

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('confirmResetPassword', $user->id)
        ->set('resetPasswordForm.password', 'new-password-123')
        ->set('resetPasswordForm.password_confirmation', 'new-password-123')
        ->call('resetPassword');

    $user->refresh();

    expect(Hash::check('new-password-123', $user->password))->toBeTrue();
});

test('admin can soft delete a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('confirmDelete', $user->id)
        ->call('deleteSelectedUser');

    expect(User::withTrashed()->where('id', $user->id)->first()->deleted_at)->not->toBeNull();
});
