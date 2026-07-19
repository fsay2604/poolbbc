# Mot de passe

## Acteur

- Utilisateur authentifié qui connaît son mot de passe actuel et souhaite le remplacer.

## Préconditions et autorisations

- La route `/settings/password` exige une session authentifiée.
- L’action cible toujours le compte de la session courante.

## Parcours

1. L’utilisateur ouvre Paramètres, puis Mot de passe.
2. Il saisit son mot de passe actuel.
3. Il saisit et confirme le nouveau mot de passe.
4. Le composant valide les trois champs et met à jour le compte.
5. Il vide les champs et émet `password-updated` pour afficher le succès.

## Règles et états terminaux

- Le mot de passe actuel est obligatoire et doit correspondre au compte authentifié.
- Le nouveau mot de passe est obligatoire, applique la règle Laravel par défaut et doit correspondre à sa confirmation.
- Toute erreur de validation vide les trois champs avant de réafficher les erreurs.
- Une mise à jour valide remplace le mot de passe; une validation invalide le conserve.
- La session courante reste active après la modification.

## Limites vérifiées

- Les tests couvrent la mise à jour valide et le refus d’un mauvais mot de passe actuel.
- Aucun scénario dédié ne vérifie ici un défaut de confirmation ou chaque contrainte de la règle de robustesse par défaut.
- L’action ne déconnecte pas les autres sessions éventuelles du compte.

## Sources et couverture

- `routes/web.php`
- `app/Http/Requests/Settings/UpdatePasswordRequest.php`
- `resources/views/livewire/settings/⚡password/password.php`
- `resources/views/livewire/settings/⚡password/password.blade.php`
- `tests/Feature/Settings/PasswordUpdateTest.php`
