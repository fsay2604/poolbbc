# Saisir et publier les résultats d’un pool

## Acteur

Gestionnaire actif du pool pour les résultats des événements locaux; administrateur système pour les résultats officiels de la saison.

## Préconditions et autorisations

- La route `pools.results`, affichée comme **Résultats**, exige de pouvoir voir le pool puis de pouvoir le gérer ou d’être administrateur système. Un membre ordinaire ne peut pas ouvrir cette page directement.
- La section **Résultats propres au pool** contient les événements locaux effectivement verrouillés, avec un résultat saisi ou déjà publiés. Seul un gestionnaire du pool peut y saisir ou modifier un résultat.
- La section **Résultats officiels de la saison** est intégrée pour les administrateurs système. La saison est fixée à celle du pool et une publication officielle concerne tous les pools de cette saison.
- La structure des rondes et événements se gère dans `pools.events`. Les membres saisissent et visualisent leurs prédictions dans `pools.predictions`.

## Parcours

1. Ouvrir **Résultats** depuis la navigation du pool.
2. Pour un événement local verrouillé, sélectionner les réponses finales parmi ses options figées.
3. Générer un aperçu des points. Cet aperçu est en lecture seule et doit correspondre exactement à la sélection qui sera confirmée.
4. En publication immédiate, publier le résultat et les points dans la même opération. En publication manuelle, enregistrer d’abord un brouillon privé et non pointé.
5. Modifier au besoin un brouillon manuel après un nouvel aperçu, puis le publier explicitement.
6. Pour corriger un résultat local déjà publié, fournir une justification, prévisualiser la nouvelle sélection et publier une nouvelle version.
7. Comme administrateur système, utiliser la section officielle intégrée pour prévisualiser le pointage de tous les pools, enregistrer ou publier le résultat, corriger une version et reprendre une publication incomplète.

## Règles et états terminaux

- Toute modification de la sélection invalide l’aperçu. La confirmation exige une empreinte correspondant exactement aux options courantes.
- L’aperçu ne crée ni résultat ni écriture de points.
- Un brouillon manuel reste privé et non pointé. Pendant sa modification, la dernière version publiée demeure la référence visible des membres.
- Un résultat publié est immuable. Une correction crée une version supérieure, conserve la version précédente et ajoute les écritures qui inversent les anciens points avant d’inscrire les nouveaux.
- Le registre de points publié alimente le total et le classement du pool. Les brouillons et traitements officiels en attente ou en échec n’y contribuent pas.
- Pour les résultats officiels, la publication suit un traitement repris de façon idempotente : les reçus terminés sont conservés et seuls les pools incomplets sont retraités.
- Une fois le résultat publié, sa sélection apparaît dans `pools.predictions`; les réponses concurrentes y suivent la règle de visibilité de leur source.

## Limites vérifiées

- Les options doivent appartenir à l’événement et respecter ses minimums et maximums de résultat. L’option « aucun candidat » ne peut pas être combinée avec une autre réponse.
- La saisie locale est refusée tant que l’événement n’est pas verrouillé. Une correction publiée exige une justification.
- Les actions relisent l’événement et revérifient l’autorisation dans leur transaction; un identifiant d’un autre pool ou d’une autre saison est refusé avant toute mutation.
- Une annulation ne crée aucun point. Un événement qui possède un résultat local en attente de publication ne peut plus être annulé.
- Les audits distinguent l’enregistrement, la modification d’un brouillon, la publication et la correction. Les résultats officiels ajoutent les étapes de mise en file, reprise, échec et finalisation.
- Cette page n’expose aucune commande de création, modification ou suppression de ronde ou d’événement, et aucun formulaire de prédiction.

## Sources et couverture

- `resources/views/components/pools/⚡results/results.php`
- `resources/views/components/pools/⚡results/results.blade.php`
- `resources/views/components/admin/⚡official-results/official-results.php`
- `app/Actions/Scoring/PreviewEventScore.php`
- `app/Actions/Scoring/PublishEventResult.php`
- `app/Actions/Scoring/PreviewSeasonEventScore.php`
- `app/Actions/Events/PublishSeasonEventResult.php`
- `app/Jobs/ScoreSeasonEventResultJob.php`
- `app/Jobs/FinalizeSeasonEventResultJob.php`
- `tests/Feature/PoolStructureAndResultsNavigationTest.php`
- `tests/Feature/EventScoringFlowTest.php`
- `tests/Feature/ResultPublicationFlowTest.php`
- `tests/Feature/ResultDraftAmendmentTest.php`
- `tests/Feature/OfficialResultPreviewTest.php`
- `tests/Feature/OfficialResultPublicationRecoveryTest.php`
