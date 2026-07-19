# Consulter et gérer le cycle de vie d’un pool

## Acteur

Membre actif pour la consultation; propriétaire ou administrateur actif du pool pour les opérations de gestion.

## Préconditions et autorisations

- Toutes les routes visant un pool précis vérifient que l’utilisateur peut le voir; un membre retiré perd immédiatement cet accès. La route `pools.index` exige seulement une session authentifiée.
- Seul un gestionnaire actif peut ouvrir les inscriptions, ordonner ou contrôler le repêchage, retirer un membre, terminer ou archiver le pool.
- Un membre actif peut quitter le pool s’il n’en est pas le propriétaire et si l’état courant l’autorise.

## Parcours

1. Ouvrir la vue d’ensemble et consulter l’état, la saison, le fuseau horaire, les membres, les rondes et les événements actifs.
2. Consulter son rang, son total publié, sa progression sur les prédictions officielles, la prochaine échéance et ses cinq dernières écritures de points.
3. Naviguer de façon persistante entre la vue d’ensemble, le repêchage lorsqu’il existe, les prédictions officielles, les rondes et événements, puis le classement.
4. En gestion, confirmer les règles, ouvrir les inscriptions et organiser les membres.
5. Démarrer puis, au besoin, suspendre ou reprendre le repêchage; un pool sans repêchage peut être activé directement après les inscriptions.
6. Lorsque tous les événements sont résolus, terminer le pool puis l’archiver.

## Règles et états terminaux

- Cycle avec repêchage: `configuration` → `registration` → `draft` → `active` → `completed` → `archived`.
- Cycle prédictions seulement: `configuration` → `registration` → `active` → `completed` → `archived`.
- Le dernier choix du repêchage active automatiquement le pool.
- Passer à `completed` exige que chaque événement local et chaque événement officiel actif soit `published` ou `cancelled`.
- `completed` et `archived` sont en lecture seule. L’archivage conserve membres, équipes, prédictions, résultats, registre de points et classement.
- Le retrait ou le départ exige une raison de 3 à 1000 caractères et produit une entrée d’audit. Une équipe déjà constituée reste dans l’historique compétitif.

## Limites vérifiées

- Aucun membre ne peut être ajouté après la fermeture des inscriptions.
- Un membre ne peut être retiré et ne peut quitter pendant un repêchage actif ou suspendu, ni après la fin du pool.
- Le propriétaire ne peut pas quitter son propre pool.
- Retirer un membre avant le repêchage réordonne les positions restantes; après constitution d’une équipe, ses données historiques et ses points sont préservés.
- Les compteurs de la vue d’ensemble ne retiennent que les associations d’événements actives du pool; les échéances disparaissent dans les états terminaux.

## Sources et couverture

- `resources/views/components/pools/⚡show/show.php`
- `app/Actions/Pools/OpenPoolRegistration.php`
- `app/Actions/Pools/ActivatePool.php`
- `app/Actions/Pools/TransitionPoolStatus.php`
- `app/Actions/Pools/RemovePoolMember.php`
- `app/Models/Pool.php`
- `tests/Feature/PoolFlowTest.php`
- `tests/Feature/PoolFirstNavigationTest.php`
