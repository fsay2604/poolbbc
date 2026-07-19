# Administration — Types d’événements standards

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- L’écran est accessible par la route `admin.event-types` (`/admin/event-types`).
- Seuls les cinq types globaux gérés par le catalogue applicatif sont affichés : patron, nominations, veto, éviction et gagnant de la saison.

## Parcours

1. Ouvrir **Administration > Types d’événements standards**.
2. Choisir un type puis ouvrir son formulaire d’édition.
3. Adapter son nom, sa question, le mode proposé aux pools, le mode de publication, les limites de sélection, l’admissibilité des candidats et le pointage par défaut.
4. Enregistrer pour appliquer ces valeurs aux futures instances créées à partir de ce type.

## Règles et états terminaux

- Les types standards sont configurables, mais ils ne peuvent être ni créés ni supprimés depuis cet écran.
- Les types personnalisés d’un pool et les types globaux hors catalogue ne sont ni listés ni modifiables ici.
- Une ronde copie la configuration courante dans chaque nouvel événement officiel. Modifier le type plus tard ne change donc pas les événements déjà créés ni leur historique.
- Lors de la synchronisation d’un nouvel événement, chaque pool reçoit cet instantané avec ses propres remplacements de pointage persistants. Une règle déjà personnalisée sur l’événement d’un pool reste indépendante.
- Une modification réussie produit l’audit `event_type.updated` avec les instantanés avant et après.

## Limites vérifiées

- Le nom est obligatoire et limité à 120 caractères; la question facultative est limitée à 500 caractères.
- Le mode doit être `roster`, `prediction` ou `hybrid`; la publication doit être immédiate ou manuelle.
- Les minimums de prédiction et de résultat sont compris entre 0 et 20. Les maximums sont compris entre 1 et 20 et ne peuvent pas être inférieurs à leur minimum.
- Les valeurs de pointage sont comprises entre -100 et 100; la pénalité de mauvaise réponse est comprise entre -100 et 0.
- L’autorisation et l’appartenance au catalogue sont revérifiées sous verrou dans une transaction; une mise à jour refusée ne produit aucun audit.

## Sources et couverture

- `routes/web.php`
- `resources/views/components/admin/⚡event-types/event-types.php`
- `app/Support/StandardEventTypeCatalog.php`
- `app/Actions/Events/UpdateStandardEventType.php`
- `app/Policies/EventTypePolicy.php`
- `tests/Feature/StandardEventTypeManagementTest.php`
