# Inscription et vérification du courriel

## Acteur

- Visiteur qui crée un compte.
- Utilisateur authentifié qui ouvre un lien de vérification ou demande un nouvel envoi.

## Préconditions et autorisations

- L’inscription et les routes de vérification du courriel sont activées dans Fortify.
- Les routes `register` et `register.store` sont réservées aux visiteurs.
- Les routes de vérification exigent une session authentifiée; le lien de vérification doit aussi porter une signature valide.

## Parcours

1. Le visiteur ouvre `/register` et fournit son nom, son adresse courriel, son mot de passe et sa confirmation.
2. L’application valide les données et crée le compte.
3. Fortify ouvre immédiatement la session et redirige vers `/dashboard`.
4. Les routes `/email/verify`, `/email/verification-notification` et `/email/verify/{id}/{hash}` restent disponibles pour le mécanisme de vérification.
5. Un lien signé valide marque l’adresse comme vérifiée puis redirige vers le tableau de bord avec `verified=1`.

## Règles et états terminaux

- Le nom est obligatoire, textuel et limité à 255 caractères.
- L’adresse courriel est obligatoire, valide, limitée à 255 caractères et unique parmi les comptes.
- Le mot de passe applique la règle Laravel par défaut et doit correspondre à sa confirmation.
- Une inscription valide aboutit à un compte authentifié; une validation invalide ne crée aucun compte.
- Un lien dont l’identifiant, le hachage ou la signature est invalide ne vérifie pas l’adresse.
- Revisiter un lien valide pour un compte déjà vérifié redirige sans émettre une seconde fois l’événement `Verified`.

## Limites vérifiées

- Le modèle `User` possède les méthodes de vérification héritées, mais n’implémente pas le contrat `MustVerifyEmail`.
- Par conséquent, l’inscription actuelle n’envoie pas automatiquement la notification standard et le middleware `verified` ne bloque pas un compte non vérifié.
- Le renvoi manuel est limité par Fortify à six requêtes par minute.
- Les tests couvrent l’inscription, la vérification par lien valide, le refus d’un mauvais hachage et le lien revisité; ils ne décrivent pas un envoi automatique après inscription.

## Sources et couverture

- `config/fortify.php`
- `app/Models/User.php`
- `app/Actions/Fortify/CreateNewUser.php`
- `app/Http/Requests/Fortify/CreateNewUserRequest.php`
- `resources/views/livewire/auth/register.blade.php`
- `resources/views/livewire/auth/verify-email.blade.php`
- `tests/Feature/Auth/RegistrationTest.php`
- `tests/Feature/Auth/EmailVerificationTest.php`
