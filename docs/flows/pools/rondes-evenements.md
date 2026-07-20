# Gérer les rondes et événements d’un pool

## Acteur

Gestionnaire actif du pool pour la structure locale et les adaptations propres au pool; administrateur système pour la structure officielle de la saison.

## Préconditions et autorisations

- La route `pools.events`, affichée comme **Rondes et événements**, exige de pouvoir voir le pool puis de pouvoir le gérer ou d’être administrateur système. Un membre ordinaire ne peut pas ouvrir cette page, même par son URL.
- La section **Structure propre au pool** appartient uniquement au pool courant. Son gestionnaire peut créer, modifier, supprimer, réordonner et piloter ses rondes et événements locaux.
- La section **Structure officielle de la saison** est intégrée sur la même page pour les administrateurs système. Elle reste commune à tous les pools de la saison et la saison du pool est fixée dans ce contexte.
- Les adaptations des associations officielles — mode, visibilité, limites de réponse et barème — restent propres au pool et relèvent de son gestionnaire tant que leurs règles sont encore modifiables.
- Cette page ne contient plus de formulaire de prédiction ni de saisie de résultat. Les réponses des membres se trouvent dans `pools.predictions` et les résultats dans `pools.results`.

## Parcours

1. Ouvrir **Rondes et événements** depuis la navigation du pool.
2. En gestion locale, créer une ronde, puis ajouter un événement en choisissant son type, son mode, sa source de choix, ses échéances, ses limites, son barème et son mode de publication.
3. Tant que la structure est encore inutilisée, modifier une ronde ou un événement. Un événement peut aussi être déplacé vers une autre ronde; ses options sont alors reconstruites à partir de sa configuration.
4. Réordonner les événements d’une ronde entièrement en brouillon, puis ouvrir, verrouiller ou annuler chaque événement local selon son calendrier.
5. Consulter, sans afficher leurs choix, quels membres ont soumis une réponse à chaque événement local ou officiel.
6. Adapter au besoin les règles d’une association officielle avant leur gel.
7. Comme administrateur système, gérer dans la section officielle intégrée les rondes et événements canoniques de la saison. Ces changements se répercutent sur tous ses pools admissibles.

## Configuration d’un événement local

| Option | Effet |
|---|---|
| Type ou modèle | Préremplit la source de réponse et des valeurs de configuration. Un modèle propre au pool peut être conservé pour réutilisation. |
| Mode | Active le pointage d’équipe, la prédiction ou les deux, dans les limites du mode de compétition du pool. |
| Source de réponse | Construit les options depuis les candidats de la saison, une réponse oui/non ou une liste personnalisée. |
| Ouverture et verrouillage | Déterminent l’état effectif. Le verrouillage doit être postérieur à l’ouverture lorsqu’elle est fournie. |
| Minimum et maximum de prédictions | Encadrent le nombre de choix qu’un membre peut soumettre. |
| Minimum et maximum du résultat | Encadrent le nombre d’options que le gestionnaire peut retenir comme réponse finale. |
| Barème | Définit les points d’équipe, les points par bonne réponse, le bonus exact, la pénalité et la possibilité d’un total négatif. |
| Publication du résultat | Choisit une publication immédiate ou l’enregistrement d’un brouillon manuel à publier plus tard. |
| Candidats inactifs et « aucun candidat » | Étendent l’instantané des options; « aucun candidat » reste mutuellement exclusif avec toute autre réponse. |

## Règles et états terminaux

- Cycle d’un événement local ou officiel : `draft` → `open` → `locked` → `result_entered` → `published`. `cancelled` est terminal.
- Les échéances effectives peuvent ouvrir ou verrouiller un événement même si la page affichait encore un ancien état. L’ouverture fige les options admissibles.
- Une ronde locale et ses événements ne peuvent être modifiés ou supprimés que s’ils sont encore des brouillons effectifs et sans prédiction, résultat, point ni projection dépendante.
- La suppression d’une ronde locale est atomique : un seul événement enfant protégé conserve la ronde et tous ses événements.
- Une fois l’événement ouvert ou utilisé, sa structure est conservée. Le gestionnaire doit poursuivre son cycle ou l’annuler avec une justification au lieu de le supprimer.
- Les adaptations d’une association officielle se figent au plus tard au verrouillage, ou dès la première réponse ou écriture de points.
- Les créations, modifications, suppressions, transitions, annulations et adaptations sont auditées. Une opération refusée ou annulée par la transaction ne laisse ni changement partiel ni audit trompeur.

## Limites vérifiées

- Chaque mutation relit le pool, la ronde ou l’événement sous transaction, vérifie l’autorisation et refuse les identifiants appartenant à un autre pool ou à une autre saison.
- Le mode de l’événement doit être compatible avec le mode du pool; les dates, limites de sélection, options et barèmes sont validés côté serveur.
- La modification d’un événement inutilisé remplace ses options dans la même transaction. Une reconstruction invalide restaure entièrement l’ancienne définition.
- Modifier un événement qui contient déjà des candidats inactifs conserve ce choix de configuration et ne retire pas silencieusement leurs options.
- Les réponses détaillées des membres ne sont jamais révélées sur cette page; seul l’état de participation est présenté aux gestionnaires.
- Un pool ne peut être terminé avant que tous ses événements locaux et toutes ses associations officielles actives soient publiés ou annulés.

## Sources et couverture

- `resources/views/components/pools/⚡events/events.php`
- `resources/views/components/pools/⚡events/events.blade.php`
- `resources/views/components/admin/⚡official-rounds/official-rounds.php`
- `app/Actions/Events/CreateEvent.php`
- `app/Actions/Events/CreateRound.php`
- `app/Actions/Events/BuildEventOptions.php`
- `app/Actions/Events/UpdateRound.php`
- `app/Actions/Events/DeleteRound.php`
- `app/Actions/Events/UpdateEvent.php`
- `app/Actions/Events/DeleteEvent.php`
- `app/Actions/Events/ReorderRoundEvents.php`
- `app/Actions/Events/SynchronizeEventLifecycle.php`
- `app/Actions/Events/UpdatePoolEventRules.php`
- `tests/Feature/LocalEventStructureManagementTest.php`
- `tests/Feature/CreateRoundTest.php`
- `tests/Feature/PoolStructureAndResultsNavigationTest.php`
- `tests/Feature/OfficialStructureManagementTest.php`
- `tests/Feature/AdministrativeAuditLogTest.php`
