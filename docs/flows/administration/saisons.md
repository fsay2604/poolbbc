# Administration — Saisons

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- L’écran est accessible par la route `admin.seasons.index` (`/admin/seasons`).

## Parcours

1. Ouvrir **Administration > Saisons**.
2. Créer une saison ou sélectionner une saison existante pour la modifier.
3. Saisir le nom, les dates facultatives de début et de fin, puis indiquer si la saison est active.
4. Enregistrer. Si la saison devient active, toute autre saison active est désactivée dans la même transaction.
5. Pour supprimer une saison admissible, ouvrir la confirmation nominative puis confirmer.

## Règles et états terminaux

- Une sauvegarde produit une saison créée ou mise à jour; elle ne crée automatiquement ni ronde ni événement.
- L’activation est exclusive au niveau applicatif : après la transaction, au plus une saison est active.
- La liste présente les saisons actives en premier, puis les plus récentes.
- La suppression est définitive et entraîne la suppression des données canoniques dépendantes lorsque la saison ne contient encore ni pool ni historique de résultat officiel.
- Les créations, modifications, désactivations automatiques et suppressions sont auditées avec l’acteur et les valeurs avant/après pertinentes.

## Limites vérifiées

- Le nom est obligatoire et limité à 255 caractères.
- Les dates sont facultatives, mais chaque valeur fournie doit être une date valide; l’écran n’impose pas actuellement que la date de fin soit postérieure à celle de début.
- Une saison liée à au moins un pool ou à un résultat officiel ne peut pas être supprimée. Elle doit être conservée afin de préserver le registre de points et l’historique officiel.
- Les contrôles d’autorisation et de suppression sont répétés lors de l’action, et les écritures sont protégées par une transaction et des verrous.

## Sources et couverture

- `routes/web.php`
- `resources/views/livewire/admin/seasons/⚡index/index.php`
- `app/Http/Requests/Admin/SaveSeasonRequest.php`
- `app/Actions/Audit/RecordAuditLog.php`
- `tests/Feature/AdminDeleteSeasonTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
