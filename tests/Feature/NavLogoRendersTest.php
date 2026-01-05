<?php

declare(strict_types=1);

use App\Models\User;

it('renders the nav logo image', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', absolute: false))
        ->assertOk()
        ->assertSee('storage/images/nav-logo.png');
});
