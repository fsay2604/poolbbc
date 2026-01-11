<?php

use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);
    $component = Volt::test('admin.users.index');

    $component->assertSee('');
});
