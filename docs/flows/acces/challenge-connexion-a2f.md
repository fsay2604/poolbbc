# Challenge de connexion A2F

## Acteur

- Utilisateur dont les identifiants principaux sont valides et dont l’authentification à deux facteurs est active.

## Préconditions et autorisations

- La fonctionnalité A2F doit être activée dans Fortify.
- Une tentative de connexion primaire doit avoir créé l’état temporaire attendu dans la session.
- L’utilisateur demeure non authentifié jusqu’à la réussite du challenge.

## Parcours

1. Après la validation du courriel et du mot de passe, Fortify redirige vers `/two-factor-challenge`.
2. L’utilisateur choisit un code TOTP à six chiffres ou un code de récupération.
3. Le formulaire envoie la méthode choisie à `two-factor.login.store`.
4. Un code valide finalise la session et redirige vers la destination prévue, par défaut le tableau de bord.
5. Un code invalide laisse le challenge ouvert avec une erreur.

## Règles et états terminaux

- Le basculement entre TOTP et code de récupération vide les valeurs de la méthode abandonnée.
- Le code TOTP est présenté sous forme de six positions numériques.
- Chaque code de récupération ne peut être utilisé qu’une fois.
- Le limiteur A2F autorise cinq tentatives par minute pour l’identifiant de connexion conservé en session.
- Ouvrir le challenge sans tentative de connexion en attente redirige vers `/login`.
- La réussite aboutit à une session authentifiée; l’échec laisse l’utilisateur visiteur.

## Limites vérifiées

- Les tests couvrent la redirection vers le challenge après les identifiants principaux et le retour à la connexion sans état A2F en attente.
- Aucun test applicatif dédié ne soumet actuellement un TOTP valide, un TOTP invalide ou un code de récupération.
- La disponibilité des codes de récupération dépend de leur génération préalable dans les [paramètres A2F](../compte/authentification-deux-facteurs.md).

## Sources et couverture

- `config/fortify.php`
- `app/Providers/FortifyServiceProvider.php`
- `resources/views/livewire/auth/two-factor-challenge.blade.php`
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/Auth/TwoFactorChallengeTest.php`
