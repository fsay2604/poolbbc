# Consulter et gérer le cycle de vie d’un pool

## Acteur

Membre actif pour la consultation; propriétaire ou administrateur actif du pool pour les opérations de gestion.

## Préconditions et autorisations

- Toutes les routes visant un pool précis vérifient que l’utilisateur peut le voir; un membre retiré perd immédiatement cet accès. La route `pools.index` exige seulement une session authentifiée.
- Seul un gestionnaire actif peut ouvrir les inscriptions, ordonner ou contrôler le repêchage, retirer un membre, terminer ou archiver le pool. Les pages **Rondes et événements** et **Résultats** lui sont également réservées, sauf leurs sections officielles qui exigent le rôle administrateur système.
- Un membre actif peut quitter le pool s’il n’en est pas le propriétaire et si l’état courant l’autorise.

## Parcours

1. Ouvrir la vue d’ensemble et consulter l’état, la saison, le fuseau horaire, les membres, les rondes et les événements actifs.
2. Consulter son rang, son total publié, sa progression combinée sur les prédictions officielles et locales, la prochaine échéance de l’une ou l’autre source et ses cinq dernières écritures de points.
3. Naviguer de façon persistante entre la vue d’ensemble, le repêchage lorsqu’il existe, les prédictions réunies et le classement. Un gestionnaire voit aussi les entrées distinctes **Rondes et événements** et **Résultats**.
4. En gestion, confirmer les règles, ouvrir les inscriptions et organiser les membres.
5. Démarrer puis, au besoin, suspendre ou reprendre le repêchage; un pool sans repêchage peut être activé directement après les inscriptions.
6. Lorsque tous les événements sont résolus, terminer le pool puis l’archiver.

## Règles et états terminaux

- Cycle avec repêchage: `configuration` → `registration` → `draft` → `active` → `completed` → `archived`.
- Cycle prédictions seulement: `configuration` → `registration` → `active` → `completed` → `archived`.
- Le dernier choix du repêchage active automatiquement le pool.
- Passer à `completed` exige que chaque événement local et chaque association officielle active soit `published` ou `cancelled`.
- `completed` et `archived` sont en lecture seule. L’archivage conserve membres, équipes, prédictions, résultats, registre de points et classement.
- Le retrait ou le départ exige une raison de 3 à 1000 caractères et produit une entrée d’audit. Une équipe déjà constituée reste dans l’historique compétitif.

## Limites vérifiées

- Aucun membre ne peut être ajouté après la fermeture des inscriptions.
- Un membre ne peut être retiré et ne peut quitter pendant un repêchage actif ou suspendu, ni après la fin du pool.
- Le propriétaire ne peut pas quitter son propre pool.
- Retirer un membre avant le repêchage réordonne les positions restantes; après constitution d’une équipe, ses données historiques et ses points sont préservés.
- Les compteurs de rondes et d’événements additionnent la structure officielle projetée et la structure propre au pool sans confondre leurs identifiants.
- La progression ne retient que les événements officiels actifs de la saison et les événements locaux configurés en prédiction ou hybride; les événements annulés et les anciennes projections locales ne sont pas comptés deux fois.
- La prochaine échéance désigne uniquement un événement actuellement ouvert aux réponses. Un événement verrouillé manuellement, avec résultat saisi, publié ou annulé n’est jamais annoncé comme répondable; les échéances disparaissent aussi dans les états terminaux du pool.

## Sources et couverture

- `resources/views/components/pools/⚡show/show.php`
- `resources/views/components/pools/⚡show/show.blade.php`
- `resources/views/components/pools/navigation.blade.php`
- `app/Actions/Pools/OpenPoolRegistration.php`
- `app/Actions/Pools/ActivatePool.php`
- `app/Actions/Pools/TransitionPoolStatus.php`
- `app/Actions/Pools/RemovePoolMember.php`
- `app/Models/Pool.php`
- `tests/Feature/PoolFlowTest.php`
- `tests/Feature/PoolFirstNavigationTest.php`
- `tests/Feature/PoolDashboardPredictionSummaryTest.php`
- `tests/Feature/UnifiedPoolPredictionsTest.php`
- `tests/Feature/PoolStructureAndResultsNavigationTest.php`
