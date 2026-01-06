<?php

declare(strict_types=1);

test('register page is translated in french', function () {
    app()->setLocale('fr');

    $this->get('/register')
        ->assertSuccessful()
        ->assertSee('Créer un compte')
        ->assertSee('Saisissez vos informations ci-dessous pour créer votre compte')
        ->assertSee('Nom complet')
        ->assertSee('Adresse e-mail')
        ->assertSee('Mot de passe')
        ->assertSee('Confirmer le mot de passe')
        ->assertSee('Vous avez déjà un compte ?')
        ->assertSee('Se connecter');
});
