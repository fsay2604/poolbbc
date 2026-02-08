<?php

declare(strict_types=1);

test('login page is translated in french', function () {
    $previousLocale = app()->getLocale();
    app()->setLocale('fr');

    try {
        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee(__('Log in to your account'))
            ->assertSee(__('Enter your email and password below to log in'))
            ->assertSee(__('Email address'))
            ->assertSee(__('Password'))
            ->assertSee(__('Forgot your password?'))
            ->assertSee(__('Remember me'))
            ->assertSee(__('Log in'))
            ->assertSee(__("Don't have an account?"))
            ->assertSee(__('Sign up'));
    } finally {
        app()->setLocale($previousLocale);
    }
});
