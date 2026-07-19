# Profil

## Acteur

- Utilisateur authentifié qui modifie son identité affichée ou son adresse courriel.

## Préconditions et autorisations

- La route `/settings/profile` exige une session authentifiée.
- Le composant est initialisé avec le nom et l’adresse courriel du compte courant.
- Un utilisateur ne peut modifier que son propre profil depuis cet écran.

## Parcours

1. L’utilisateur ouvre Paramètres, puis Profil.
2. Il modifie son nom, son adresse courriel ou les deux.
3. Le composant valide les données et enregistre le compte.
4. Si l’adresse a changé, `email_verified_at` est remis à `null`.
5. Le composant émet `profile-updated` et affiche le message de succès.
6. Le même écran donne accès au parcours distinct de [suppression du compte](suppression-compte.md).

## Règles et états terminaux

- Le nom est obligatoire, textuel et limité à 255 caractères.
- L’adresse courriel est obligatoire, convertie en minuscules, valide, limitée à 255 caractères et unique en excluant le compte courant.
- Une modification valide persiste les nouvelles valeurs; une validation invalide laisse le compte inchangé.
- Conserver la même adresse conserve aussi son état de vérification.
- Changer l’adresse invalide son état de vérification, même si la vérification n’est actuellement pas imposée à la navigation.

## Limites vérifiées

- Le modèle `User` n’implémente pas `MustVerifyEmail`; la branche d’interface proposant le renvoi du courriel de vérification n’est donc pas rendue actuellement.
- L’écran permet de modifier uniquement le nom et l’adresse courriel, pas l’avatar.
- Les tests couvrent l’affichage, la mise à jour, l’invalidation d’une adresse modifiée et la conservation de l’état quand l’adresse ne change pas.

## Sources et couverture

- `routes/web.php`
- `app/Models/User.php`
- `app/Http/Requests/Settings/UpdateProfileRequest.php`
- `resources/views/livewire/settings/⚡profile/profile.php`
- `resources/views/livewire/settings/⚡profile/profile.blade.php`
- `tests/Feature/Settings/ProfileUpdateTest.php`
