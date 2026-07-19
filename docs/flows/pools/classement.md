# Consulter le classement d’un pool

## Acteur

Membre actif autorisé à voir le pool.

## Préconditions et autorisations

- Le classement est strictement limité au pool courant; il n’existe aucun classement global entre pools.
- Il repose sur les membres compétitifs: membres actifs et anciens membres qui possèdent encore une équipe, une prédiction ou une écriture de points historique.
- Seules les écritures publiées du registre de points contribuent aux totaux.

## Parcours

1. Ouvrir `pools.leaderboard` depuis la navigation du pool.
2. Consulter la position, le membre, le nombre de candidats actifs dans son équipe, la variation de rang et le total.
3. Filtrer facultativement par ronde officielle ou locale, puis par type d’événement.
4. Consulter, pour chaque membre, le détail des écritures: événement, raison, ronde, valeur et éventuelle correction inverse.

## Règles et états terminaux

- Les membres sont triés par total décroissant; des totaux identiques partagent le même rang.
- Un membre actif sans point reste classé avec un total de zéro.
- Une correction n’écrase pas l’historique: le registre contient l’écriture initiale, son écriture inverse et les points de la nouvelle version.
- La variation non filtrée compare le rang courant au rang antérieur à la plus récente position de ronde ayant des points publiés, qu’elle soit officielle ou locale.
- Une vue filtrée recalcule le rang sur son sous-ensemble et masque la variation, qui ne serait plus comparable au classement général.
- Les pools terminés et archivés conservent ce classement en lecture seule.

## Limites vérifiées

- Le total affiché correspond à la somme des écritures détaillées retenues par les mêmes filtres.
- Les résultats en brouillon, en cours ou en échec et les prédictions non soumises ne produisent aucune écriture publiée.
- Les anciens membres ne disparaissent pas s’ils ont participé à la compétition; leur équipe et leur historique restent attribués.
- Le classement général est mis en cache brièvement et invalidé après les publications ou reconstructions de pointage; une vue filtrée est recalculée directement.
- Le volume de requêtes reste borné lorsque le nombre de membres augmente.

## Sources et couverture

- `resources/views/components/pools/⚡leaderboard/leaderboard.php`
- `app/Actions/Leaderboards/BuildPoolLeaderboard.php`
- `app/Actions/Leaderboards/RebuildPoolScoreProjection.php`
- `app/Models/PointEntry.php`
- `tests/Feature/PoolLeaderboardLedgerTest.php`
- `tests/Feature/EventScoringFlowTest.php`
- `tests/Feature/CanonicalLedgerImmutabilityTest.php`
