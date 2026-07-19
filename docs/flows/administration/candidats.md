# Administration — Candidats

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- Une saison active est nécessaire pour créer ou enregistrer un candidat.
- L’écran est accessible par la route `admin.houseguests.index` (`/admin/houseguests`) et affiche les candidats de la saison active.

## Parcours

1. Ouvrir **Administration > Candidats**.
2. Créer un candidat ou sélectionner une carte existante.
3. Renseigner le nom, le sexe, les professions, l’ordre d’affichage et le statut actif; ajouter éventuellement un avatar.
4. Enregistrer. Lors d’un remplacement d’avatar, l’ancien fichier est retiré du disque public.
5. Pour supprimer un candidat, ouvrir la confirmation nominative puis confirmer; son avatar est ensuite retiré.

## Règles et états terminaux

- Un enregistrement crée ou met à jour le candidat dans la saison active et remet le formulaire en mode création.
- Les candidats sont ordonnés par ordre d’affichage, puis par nom.
- Le statut actif contrôle normalement l’admissibilité aux nouveaux instantanés d’options. Un type d’événement peut toutefois inclure explicitement les candidats inactifs.
- Après la publication ou la correction d’un résultat officiel, l’activité des candidats de la saison est reconstruite à partir des résultats d’éviction publiés; une valeur modifiée manuellement peut donc être recalculée.
- La création, la modification et la suppression sont auditées avec l’acteur et un instantané des attributs concernés.

## Limites vérifiées

- Le nom est obligatoire et limité à 255 caractères; le sexe doit être `M` ou `F`.
- Chaque profession doit appartenir au catalogue applicatif, et l’ordre d’affichage doit être un entier positif ou nul.
- L’avatar doit être une image d’au plus 2 Mo.
- La suppression est physique. Les contraintes référentielles peuvent la refuser lorsqu’un candidat est encore utilisé par une donnée qui doit être conservée, notamment un choix de repêchage ou une option locale.
- Sans saison active, la liste est vide et l’enregistrement est bloqué.

## Sources et couverture

- `routes/web.php`
- `resources/views/livewire/admin/houseguests/⚡index/index.php`
- `app/Http/Requests/Admin/SaveHouseguestRequest.php`
- `app/Actions/Houseguests/RebuildSeasonHouseguestActivity.php`
- `tests/Feature/AdminHouseguestAvatarTest.php`
- `tests/Feature/AdminHouseguestDeleteTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
