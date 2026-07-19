# Suppression du compte

## Acteur

- Utilisateur authentifié qui souhaite supprimer définitivement son propre compte.

## Préconditions et autorisations

- Le formulaire se trouve dans `/settings/profile` et exige une session authentifiée.
- L’utilisateur doit confirmer l’opération avec son mot de passe actuel.
- La suppression ne peut réussir que si aucune clé étrangère restrictive ne référence encore le compte.

## Parcours

1. L’utilisateur ouvre Paramètres, puis Profil, et choisit « Supprimer le compte ».
2. Un dialogue rappelle le caractère irréversible de l’opération.
3. L’utilisateur saisit son mot de passe actuel et confirme.
4. Le composant valide le mot de passe, ferme la session, puis appelle `forceDelete` sur le compte.
5. Après une suppression réussie, il redirige vers `/`, puis la route d’accueil renvoie vers `/login`.

## Règles et états terminaux

- Le mot de passe est obligatoire et doit correspondre au compte authentifié.
- Un mot de passe invalide conserve le compte et laisse la validation en erreur.
- `forceDelete` retire physiquement le compte; ce parcours n’utilise pas la suppression logique du modèle `User`.
- Les références `restrictOnDelete` des pools, adhésions, événements ou résultats empêchent la base de supprimer un compte encore utilisé par ces enregistrements.
- Quand aucune référence restrictive ne subsiste, l’état terminal est un compte absent et une session fermée.

## Limites vérifiées

- Le composant ne réalise aucune vérification préalable ni désassociation des pools et événements avant `forceDelete`.
- La déconnexion précède la suppression; une contrainte de base qui refuse `forceDelete` peut donc laisser le compte existant mais sa session fermée.
- Le fichier éventuellement référencé par `avatar_url` n’est pas supprimé explicitement de l’espace de stockage par ce parcours.
- Les tests couvrent un compte sans relation et le refus d’un mauvais mot de passe; ils ne couvrent pas encore le refus provoqué par une référence restrictive.

## Sources et couverture

- `routes/web.php`
- `app/Http/Requests/Settings/DeleteUserRequest.php`
- `resources/views/livewire/settings/⚡delete-user-form/delete-user-form.php`
- `resources/views/livewire/settings/⚡delete-user-form/delete-user-form.blade.php`
- `database/migrations/2026_07_14_130825_create_pools_table.php`
- `database/migrations/2026_07_14_130827_create_pool_members_table.php`
- `database/migrations/2026_07_14_130839_create_events_table.php`
- `database/migrations/2026_07_14_130848_create_event_results_table.php`
- `tests/Feature/Settings/ProfileUpdateTest.php`
