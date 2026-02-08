<?php

declare(strict_types=1);

test('forgot password page is translated in french', function () {
    $previousLocale = app()->getLocale();
    app()->setLocale('fr');

    try {
        $this->get(route('password.request'))
            ->assertSuccessful()
            ->assertSee(__('Forgot password'))
            ->assertSee(__('Enter your email to receive a password reset link'))
            ->assertSee(__('Email address'))
            ->assertSee(__('Email password reset link'))
            ->assertSee(__('Or, return to'))
            ->assertSee(__('Log in'));
    } finally {
        app()->setLocale($previousLocale);
    }
});
