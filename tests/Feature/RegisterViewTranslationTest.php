<?php

declare(strict_types=1);

test('register page is translated in french', function () {
    $previousLocale = app()->getLocale();
    app()->setLocale('fr');

    try {
        $this->get('/register')
            ->assertSuccessful()
            ->assertSee(__('Create an account'))
            ->assertSee(__('Enter your details below to create your account'))
            ->assertSee(__('Full name'))
            ->assertSee(__('Email address'))
            ->assertSee(__('Password'))
            ->assertSee(__('Confirm Password'))
            ->assertSee(__('Already have an account?'))
            ->assertSee(__('Log in'));
    } finally {
        app()->setLocale($previousLocale);
    }
});
