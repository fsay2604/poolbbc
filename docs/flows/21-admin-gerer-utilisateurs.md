# Administrateur — gérer les utilisateurs

**Acteur** : administrateur.

## Sous-parcours

- Créer un utilisateur avec avatar, nom, courriel, rôle et mot de passe.
- Modifier nom, courriel, avatar ou rôle.
- Promouvoir ou rétrograder directement depuis le menu d’actions.
- Réinitialiser le mot de passe dans un dialogue.
- Supprimer logiquement un autre utilisateur après confirmation.

## Garde-fous actuels

- Un administrateur ne peut ni retirer son propre rôle ni supprimer son propre compte depuis cet écran.
- Le courriel doit rester unique.
- La suppression administrative conserve la ligne en base via `SoftDeletes`.

## Écarts UX et intégrité observés

- Changer le rôle est immédiat, sans confirmation ni historique.
- La grille avatar/nom contient une combinaison incohérente `grid-cols-2` avec `col-span-10`, susceptible de créer des colonnes implicites.
- Aucun filtre, recherche, tri, pagination ou statut « archivé ».
- Le texte parle de « désactiver », mais le comportement est une suppression logique non réversible depuis l’interface.
- Un utilisateur supprimé conserve ses scores; le classement charge les auteurs sans `withTrashed()` puis indexe la collection directement, ce qui peut provoquer une erreur.
- Les avatars peuvent rester orphelins lors des suppressions.

## Sources et couverture

- `resources/views/livewire/admin/users/⚡index/`.
- `app/Models/User.php`.
- `tests/Feature/AdminUserManagementTest.php`, `tests/Feature/Livewire/Admin/Users/IndexTest.php`.

