# Récupération du mot de passe

**Acteur** : visiteur qui ne peut plus se connecter.

## Parcours

```mermaid
flowchart TD
    A["Mot de passe oublié"] --> B["Saisir l'adresse courriel"]
    B --> C["Demander le lien de réinitialisation"]
    C --> D["Recevoir un message neutre"]
    D --> E["Ouvrir le lien avec jeton"]
    E --> F["Saisir courriel et nouveau mot de passe confirmé"]
    F --> G{"Jeton et données valides ?"}
    G -->|non| F
    G -->|oui| H["Mettre à jour le mot de passe"]
    H --> I["Redirection vers la connexion"]
```

## Branches et sécurité

- Le formulaire de demande passe par le broker de mots de passe Fortify.
- Le formulaire final contient le jeton signé dans un champ caché.
- Les mêmes règles de mot de passe sont utilisées à l’inscription et à la réinitialisation.

## Écarts UX observés

- Le courriel saisi n’est pas restauré avec `old('email')` sur le premier formulaire après une erreur.
- Aucun état de traitement sur les deux soumissions.
- Le parcours ne donne pas de voie explicite vers le support lorsque le courriel n’arrive pas.

## Sources et couverture

- `resources/views/livewire/auth/forgot-password.blade.php`.
- `resources/views/livewire/auth/reset-password.blade.php`.
- `app/Actions/Fortify/ResetUserPassword.php`.
- `tests/Feature/Auth/PasswordResetTest.php`.

