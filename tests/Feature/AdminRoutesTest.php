<?php

use App\Models\User;

it('forbids admin routes for non-admin users', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get('/admin/seasons')->assertForbidden();
    $this->actingAs($user)->get('/admin/weeks')->assertForbidden();
    $this->actingAs($user)->get('/admin/houseguests')->assertForbidden();
});

it('allows admin routes for admin users', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/seasons')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/weeks')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/houseguests')->assertSuccessful();
});
