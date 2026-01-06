<?php

declare(strict_types=1);

test('login page is translated in french', function () {
    app()->setLocale('fr');

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('Connectez-vous à votre compte')
        ->assertSee('Saisissez votre e-mail et votre mot de passe ci-dessous pour vous connecter')
        ->assertSee('Adresse e-mail')
        ->assertSee('Mot de passe')
        ->assertSee('Mot de passe oublié ?')
        ->assertSee('Se souvenir de moi')
        ->assertSee('Se connecter')
        ->assertSee('Vous n’avez pas de compte ?')
        ->assertSee('S’inscrire');
});
