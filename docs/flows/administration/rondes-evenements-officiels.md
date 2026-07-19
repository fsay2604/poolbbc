# Administration — Rondes et événements officiels

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- Au moins une saison doit exister pour utiliser l’assistant.
- L’écran est accessible par la route `admin.official-rounds` (`/admin/official-rounds`).

## Parcours

1. Choisir la saison, le nom, l’ouverture, le verrouillage et une structure : standard, sans veto, double éviction ou finale.
2. Réviser le résumé puis créer la ronde. Le système clone les types standards requis en événements officiels indépendants.
3. Tant qu’un événement est encore au brouillon et que ses options ne sont pas figées, adapter son nom, sa question, son mode, ses dates, ses limites de sélection et son mode de publication.
4. Ouvrir l’événement pour figer ses options, puis le verrouiller afin de fermer les réponses. Les dates planifiées peuvent aussi faire avancer son état effectif.
5. Au besoin, annuler un événement encore au brouillon, ouvert ou verrouillé en fournissant une justification.

## Règles et états terminaux

- Une ronde reçoit la position suivant la dernière ronde de sa saison. Chaque structure crée sa propre composition et son propre instantané de règles.
- Les événements admissibles sont projetés vers les pools de la même saison, sauf les pools terminés ou archivés. Le mode est adapté aux capacités de chaque pool.
- Une modification d’événement actualise seulement les projections qui héritent encore des règles officielles; les règles personnalisées par un gestionnaire de pool sont préservées.
- Le cycle normal est `draft → open → locked → result_entered → published`. Une correction prépare une nouvelle version sans retirer la version publiée; `cancelled` est terminal.
- L’ouverture fige les options admissibles. Le verrouillage transforme les prédictions soumises en prédictions verrouillées.
- La création de ronde, l’adaptation d’un événement et chaque transition explicite sont auditées avec l’acteur; l’annulation conserve aussi sa justification.

## Limites vérifiées

- Le nom de ronde est obligatoire et limité à 255 caractères. L’ouverture doit être future et le verrouillage doit lui être postérieur.
- L’adaptation d’un événement impose également une ouverture future, un verrouillage ultérieur et des maximums de sélection supérieurs ou égaux aux minimums.
- Les règles d’un événement ne peuvent plus changer après l’ouverture, le gel des options ou la première réponse enregistrée.
- Une annulation exige une justification de 3 à 1 000 caractères et ne peut pas être annulée ou rouverte ensuite.
- Les créations, adaptations, transitions et synchronisations critiques sont transactionnelles et relisent les entités sous verrou.

## Sources et couverture

- `routes/web.php`
- `resources/views/components/admin/⚡official-rounds/official-rounds.php`
- `app/Actions/Events/CreateSeasonRoundFromTemplate.php`
- `app/Actions/Events/TransitionSeasonEvent.php`
- `app/Actions/Events/SynchronizeOfficialPoolEvents.php`
- `app/Models/SeasonEvent.php`
- `tests/Feature/OfficialAdminFlowTest.php`
- `tests/Feature/StandardEventTypeManagementTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
