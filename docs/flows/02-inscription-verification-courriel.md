# Inscription et vérification de l’adresse courriel

**Acteur** : visiteur.

**Précondition** : aucune session authentifiée.

## Parcours nominal attendu

```mermaid
flowchart TD
    A["Ouvrir /register"] --> B["Saisir nom, courriel et mot de passe confirmé"]
    B --> C{"Données valides et courriel unique ?"}
    C -->|non| B
    C -->|oui| D["Créer le compte et ouvrir la session"]
    D --> E["Envoyer le courriel de vérification"]
    E --> F["Écran Vérifiez votre courriel"]
    F --> G["Cliquer le lien signé"]
    G --> H["Marquer le courriel vérifié"]
    H --> I["Tableau de bord"]
```

## Comportement réellement observé

- Fortify active l’inscription et la vérification du courriel.
- La route du tableau de bord utilise le middleware `verified`.
- Le modèle `User` **n’implémente toutefois pas** `MustVerifyEmail`; l’import est commenté. La vérification n’est donc pas imposée de manière cohérente et l’interface de profil ne montre pas l’état non vérifié.

## Branches

- Courriel déjà utilisé ou mot de passe invalide : retour au formulaire avec erreurs.
- Lien expiré/invalide : la vérification échoue.
- Depuis l’écran d’attente : renvoyer le courriel ou se déconnecter.
- Changer de courriel depuis le profil remet `email_verified_at` à `null`.

## Écarts UX observés

- Le parcours attendu et le contrat du modèle divergent, ce qui peut laisser entrer un compte non vérifié sans explication.
- Aucun état de chargement sur l’inscription ou le renvoi du courriel.
- Le message de succès du renvoi ne rappelle pas l’adresse ciblée ni le délai d’expiration.

## Sources et couverture

- `config/fortify.php`.
- `app/Models/User.php`.
- `resources/views/livewire/auth/register.blade.php` et `verify-email.blade.php`.
- `tests/Feature/Auth/RegistrationTest.php`, `EmailVerificationTest.php`.

