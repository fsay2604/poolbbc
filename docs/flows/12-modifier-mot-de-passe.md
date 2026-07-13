# Modifier le mot de passe

**Acteur** : utilisateur authentifié.

## Parcours

1. Ouvrir Paramètres, puis Mot de passe.
2. Saisir le mot de passe actuel.
3. Saisir et confirmer le nouveau mot de passe.
4. Valider le mot de passe actuel et les règles de robustesse.
5. Mettre à jour le compte, vider les trois champs et afficher le succès.

## Branches

- En cas d’erreur, les trois champs sont volontairement vidés avant de réafficher les messages.
- La session courante reste active après le changement.

## Écarts UX observés

- Vider le nouveau mot de passe et sa confirmation pour une simple erreur sur le mot de passe actuel force une ressaisie complète.
- Les champs n’offrent pas l’option visuelle `viewable` utilisée dans les formulaires d’authentification.
- Aucun indicateur de robustesse, état de chargement ou confirmation que les autres sessions restent actives.

## Sources et couverture

- `resources/views/livewire/settings/⚡password/`.
- `app/Http/Requests/Settings/UpdatePasswordRequest.php`.
- `tests/Feature/Settings/PasswordUpdateTest.php`.

