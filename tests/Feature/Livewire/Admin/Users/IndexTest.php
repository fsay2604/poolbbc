<?php

use App\Models\User;
use Livewire\Livewire;

it('can render', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);
    $component = Livewire::test('admin.users.index');

    $component->assertSee('');
});
