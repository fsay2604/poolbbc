# Récupération du mot de passe

## Acteur

- Visiteur qui ne peut plus ouvrir sa session et contrôle l’adresse courriel de son compte.

## Préconditions et autorisations

- La récupération des mots de passe est activée dans Fortify et utilise le broker `users`.
- Les écrans et actions de récupération sont réservés aux visiteurs.
- La réinitialisation finale nécessite le jeton produit par le broker et l’adresse courriel du compte visé.

## Parcours

1. Depuis la connexion, le visiteur ouvre `/forgot-password`.
2. Il soumet son adresse courriel à `password.email`.
3. Pour un compte admissible, le broker envoie une notification contenant un lien de réinitialisation.
4. Le lien ouvre `/reset-password/{token}` avec le jeton et l’adresse courriel.
5. Le visiteur saisit puis confirme son nouveau mot de passe.
6. `ResetUserPassword` met à jour le mot de passe et Fortify redirige vers `/login` avec un état de succès.

## Règles et états terminaux

- La demande exige une adresse courriel valide gérée par le broker de mots de passe.
- Le nouveau mot de passe applique la règle Laravel par défaut et doit correspondre à sa confirmation.
- Le jeton, l’adresse courriel et les données doivent tous être valides pour modifier le compte.
- Une réinitialisation réussie remplace le mot de passe et laisse l’utilisateur sur la page de connexion.
- Une demande ou une réinitialisation invalide affiche les erreurs sans modifier le mot de passe.

## Limites vérifiées

- Les tests couvrent le rendu des deux écrans, l’envoi de la notification et la réinitialisation avec un jeton valide.
- L’expiration, la réutilisation et le refus explicite d’un jeton invalide reposent sur le broker Laravel et n’ont pas de scénario applicatif dédié.
- Le formulaire de demande ne restaure pas explicitement l’adresse avec `old('email')` après une erreur.

## Sources et couverture

- `config/fortify.php`
- `app/Actions/Fortify/ResetUserPassword.php`
- `app/Http/Requests/Fortify/ResetUserPasswordRequest.php`
- `resources/views/livewire/auth/forgot-password.blade.php`
- `resources/views/livewire/auth/reset-password.blade.php`
- `tests/Feature/Auth/PasswordResetTest.php`
