# Accueil, connexion et déconnexion

## Acteur

- Visiteur qui souhaite ouvrir une session.
- Utilisateur authentifié qui accède à l’accueil ou ferme sa session.

## Préconditions et autorisations

- La route `/` est publique et redirige selon l’état de la session.
- Les routes `login` et `login.store` sont réservées aux visiteurs; un utilisateur déjà authentifié en est redirigé.
- La route `logout` exige une session authentifiée et accepte uniquement une requête `POST` protégée par CSRF.

## Parcours

1. Un visiteur ouvre `/` et est redirigé vers `/login`.
2. Il saisit son adresse courriel et son mot de passe, avec l’option facultative « Se souvenir de moi ».
3. Fortify valide les identifiants.
4. Sans authentification à deux facteurs, la session est ouverte et l’utilisateur est redirigé vers `/dashboard`.
5. Avec l’authentification à deux facteurs, la connexion reste incomplète et se poursuit dans le [challenge A2F](challenge-connexion-a2f.md).
6. Depuis le menu utilisateur, l’action de déconnexion termine la session puis redirige vers l’accueil, qui renvoie vers la connexion.

## Règles et états terminaux

- Une connexion invalide conserve l’état visiteur et associe l’erreur au champ `email`.
- Le limiteur de connexion autorise cinq tentatives par minute pour une même combinaison adresse courriel normalisée et adresse IP.
- Une connexion valide sans A2F aboutit à une session authentifiée sur le tableau de bord.
- La déconnexion invalide la session et régénère le jeton CSRF; l’utilisateur redevient visiteur.
- Un utilisateur déjà authentifié qui ouvre `/` est envoyé directement au tableau de bord.

## Limites vérifiées

- Les tests couvrent le rendu de la connexion, la connexion valide, le refus d’un mauvais mot de passe, la branche A2F et la déconnexion.
- Les redirections de `/` sont couvertes séparément pour un visiteur et un utilisateur authentifié.
- Le comportement de l’option « Se souvenir de moi » n’a pas de scénario automatisé dédié.

## Sources et couverture

- `routes/web.php`
- `config/fortify.php`
- `app/Providers/FortifyServiceProvider.php`
- `resources/views/livewire/auth/login.blade.php`
- `resources/views/components/layouts/app/sidebar.blade.php`
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/HomeRedirectTest.php`
