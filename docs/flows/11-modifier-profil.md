# Modifier le profil

**Acteur** : utilisateur authentifié.

## Parcours

1. Ouvrir Paramètres, puis Profil.
2. Modifier le nom et/ou l’adresse courriel.
3. Valider l’unicité et le format du courriel.
4. Si le courriel change, remettre `email_verified_at` à `null`.
5. Enregistrer et afficher un message temporaire de succès.
6. Si le compte est soumis à vérification, permettre le renvoi du courriel.

## État terminal

- Le menu utilisateur reflète le nouveau nom/courriel après le prochain rendu pertinent.
- Une adresse modifiée devrait replacer l’utilisateur dans le flow de vérification.

## Écarts UX observés

- Comme `User` n’implémente pas `MustVerifyEmail`, la branche de renvoi intégrée au profil est actuellement inatteignable.
- Aucun indicateur de modifications non enregistrées ni état de chargement.
- Le message de succès disparaît après deux secondes et n’utilise pas de région `aria-live` explicite.
- L’avatar du compte n’est pas modifiable depuis le profil, bien qu’il existe dans le modèle et dans l’administration.

## Sources et couverture

- `resources/views/livewire/settings/⚡profile/`.
- `app/Http/Requests/Settings/UpdateProfileRequest.php`.
- `tests/Feature/Settings/ProfileUpdateTest.php`.

