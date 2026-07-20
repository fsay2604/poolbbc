# Administration — Rondes et événements officiels

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- Au moins une saison doit exister. Les événements autonomes exigent aussi un type d’événement standard géré par l’application.
- L’écran de structure est accessible par `admin.official-rounds` (`/admin/official-rounds`). La saisie des résultats se trouve dans `admin.official-results`.

## Parcours

1. Sélectionner une saison pour afficher ses rondes et leurs événements dans l’ordre officiel.
2. Créer une ronde avec l’assistant en choisissant son nom, ses dates, ses heures d’ouverture et de verrouillage, puis une structure : standard, sans veto, double éviction ou finale.
3. Ajouter au besoin un événement autonome à une ronde existante. Le type standard choisi fournit la source de réponse et l’instantané initial du barème.
4. Modifier les métadonnées d’une ronde : nom, début et fin. Cette modification ne déplace jamais automatiquement les dates de ses événements.
5. Tant qu’un événement est encore un brouillon effectif, modifier sa configuration, puis l’ouvrir et le verrouiller lorsque la collecte des réponses doit commencer et se terminer.
6. Supprimer physiquement une ronde ou un événement uniquement s’il est encore inutilisé. Dès qu’une trace irréversible existe, conserver la structure et annuler l’événement avec une justification.

## Configuration d’un événement

| Option | Effet |
|---|---|
| Type standard | Détermine la source de réponse et copie le barème canonique. Seuls les types globaux standards gérés sont acceptés. |
| Nom et question | Définissent le libellé administratif et la consigne montrée aux membres. |
| Mode par défaut | Détermine si la projection dans les pools utilise les prédictions, les alignements ou le mode hybride, selon les capacités du pool. |
| Ouverture et verrouillage | Pilotent l’état effectif. Le verrouillage doit être postérieur à l’ouverture. |
| Minimum et maximum de prédictions | Encadrent le nombre de choix qu’un membre peut soumettre. |
| Minimum et maximum du résultat | Encadrent le nombre d’options que l’administrateur peut retenir comme réponse officielle. |
| Publication du résultat | Choisit entre une publication immédiate et un brouillon manuel à publier plus tard. |
| Inclure les candidats inactifs | Ajoute les candidats inactifs à l’instantané des options admissibles lors de l’ouverture. |
| Autoriser « aucun candidat » | Ajoute une option explicite mutuellement exclusive avec toute autre réponse. |

## Règles et états terminaux

- Une nouvelle ronde prend la position suivant la dernière ronde de la saison. Un événement autonome prend la position suivant le dernier événement de sa ronde.
- Chaque événement officiel est projeté vers les pools admissibles de la saison. Les projections qui héritent encore des règles officielles sont resynchronisées lors d’une modification; les personnalisations propres à un pool sont conservées.
- Le cycle normal est `draft → open → locked → result_entered → published`. `cancelled` est terminal.
- L’ouverture fige les options admissibles. Le verrouillage transforme les prédictions soumises en prédictions verrouillées.
- Une ronde en brouillon est supprimable seulement si chacun de ses événements est lui-même supprimable. La vérification est atomique : un seul enfant proté empêche toute la suppression.
- Un événement est supprimable seulement s’il est encore au brouillon effectif, non ouvert, non figé et sans prédiction, résultat ni point. Dans tous les autres cas, il doit être annulé.
- La base de données applique les mêmes barrières aux suppressions directes d’événements, de rondes et de saisons, sous SQLite, MySQL et MariaDB.
- Les créations, modifications, suppressions, transitions et annulations sont auditées. Une opération refusée ne laisse ni modification partielle ni entrée d’audit.

## Limites vérifiées

- Les noms sont obligatoires et limités à 255 caractères. Les dates de fin de ronde, de verrouillage et les maximums de sélection doivent respecter leurs bornes inférieures.
- Les règles d’un événement ne peuvent plus changer après son ouverture effective, le gel des options, la première réponse, un résultat ou une écriture de points.
- Une annulation exige une justification de 3 à 1 000 caractères et ne peut pas être annulée ou rouverte ensuite.
- Les actions relisent et reverrouillent les entités dans leur transaction avant de revérifier les autorisations et les dépendances.

## Sources et couverture

- `routes/web.php`
- `resources/views/components/admin/⚡official-rounds/official-rounds.php`
- `resources/views/components/admin/⚡official-rounds/official-rounds.blade.php`
- `app/Actions/Events/CreateSeasonRoundFromTemplate.php`
- `app/Actions/Events/CreateSeasonEvent.php`
- `app/Actions/Events/UpdateSeasonRound.php`
- `app/Actions/Events/UpdateSeasonEvent.php`
- `app/Actions/Events/DeleteSeasonRound.php`
- `app/Actions/Events/DeleteSeasonEvent.php`
- `app/Actions/Events/TransitionSeasonEvent.php`
- `app/Actions/Events/SynchronizeOfficialPoolEvents.php`
- `database/migrations/2026_07_19_195408_enforce_safe_official_structure_deletions.php`
- `tests/Feature/OfficialStructureManagementTest.php`
- `tests/Feature/OfficialAdminFlowTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
