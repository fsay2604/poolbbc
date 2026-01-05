<?php

declare(strict_types=1);

use App\Models\User;

it('redirects guests to login from home', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

it('redirects authenticated users to dashboard from home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard', absolute: false));
});
