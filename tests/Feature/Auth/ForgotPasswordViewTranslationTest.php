<?php

declare(strict_types=1);

test('forgot password page is translated in french', function () {
    app()->setLocale('fr');

    $this->get(route('password.request'))
        ->assertSuccessful()
        ->assertSee('Mot de passe oublié')
        ->assertSee('Saisissez votre e-mail pour recevoir un lien de réinitialisation du mot de passe')
        ->assertSee('Adresse e-mail')
        ->assertSee('Envoyer le lien de réinitialisation')
        ->assertSee('Ou, revenir à')
        ->assertSee('Se connecter');
});
