# Administration — Utilisateurs

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- L’écran est accessible par la route `admin.users.index` (`/admin/users`).

## Parcours

1. Ouvrir **Administration > Utilisateurs**.
2. Créer un compte avec nom, courriel, rôle, mot de passe confirmé et avatar facultatif, ou modifier un compte existant.
3. Utiliser le menu d’actions pour promouvoir ou rétrograder un autre compte, réinitialiser son mot de passe, ou demander sa suppression.
4. Confirmer les actions destructives dans leur dialogue respectif.

## Règles et états terminaux

- La création exige un mot de passe; la modification générale ne change pas le mot de passe existant.
- Le remplacement d’un avatar supprime l’ancien fichier du disque public.
- La suppression administrative est logique : le compte disparaît de cette liste, mais sa ligne et ses références historiques sont conservées.
- Aucun mécanisme de restauration n’est exposé sur cet écran, et l’avatar n’est pas supprimé lors de la suppression logique.
- Les créations, modifications, changements de rôle, réinitialisations de mot de passe et suppressions sont audités. Les mots de passe ne sont jamais inscrits dans les métadonnées d’audit.

## Limites vérifiées

- Le nom est obligatoire et limité à 255 caractères; le courriel est obligatoire, valide et unique, y compris par rapport aux comptes supprimés logiquement.
- Les avatars doivent être des images d’au plus 2 Mo.
- Un mot de passe créé ou réinitialisé doit contenir au moins huit caractères et correspondre à sa confirmation.
- Le raccourci de changement de rôle et la suppression refusent de cibler le compte administrateur courant. L’édition générale du compte courant demeure une action distincte.
- Toutes les mutations relisent la cible sous verrou dans une transaction avant de l’enregistrer.

## Sources et couverture

- `routes/web.php`
- `resources/views/livewire/admin/users/⚡index/index.php`
- `app/Http/Requests/Admin/SaveUserRequest.php`
- `app/Http/Requests/Admin/ResetUserPasswordRequest.php`
- `app/Models/User.php`
- `tests/Feature/AdminUserManagementTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
