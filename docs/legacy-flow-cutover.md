# Cutover du flow legacy

Le nouveau parcours reste réversible pendant la période d’observation. Aucune table historique ne doit être supprimée dans le même déploiement que le cutover.

## Déploiement

1. Exécuter les migrations applicatives.
2. Lancer `php artisan legacy:migrate-official-pools` autant de fois que nécessaire; la commande est idempotente.
3. Lancer `php artisan legacy:shadow-compare --allow-draft-exclusions --json`. Le code de sortie doit être `0`.
4. Démarrer un worker de queue pour les jobs de scoring et vérifier les jobs échoués.
5. Définir `LEGACY_FLOW_CUTOVER=true`, `LEGACY_FLOW_CANONICAL_IS_AUTHORITATIVE=true` et `LEGACY_FLOW_OBSERVATION_STARTED_AT` à la date UTC du cutover, puis vider le cache de configuration. Le marqueur d’autorité est volontairement persistant: il empêche de rouvrir des écritures legacy obsolètes après les premières écritures canoniques.
6. Lancer `php artisan legacy:shadow-compare --allow-draft-exclusions --record`. Cette première observation est liée au marqueur d’autorité et ne peut plus être modifiée ou supprimée.
7. Ne plus relancer l’import après cette bascule : `legacy:migrate-official-pools` refuse volontairement toute exécution lorsque le flow canonique est autoritaire.
8. Vérifier les redirections historiques, les soumissions, la publication, les corrections et le classement.

## Annulation avant les premières écritures canoniques

Tant que `LEGACY_FLOW_CANONICAL_IS_AUTHORITATIVE=false`, définir `LEGACY_FLOW_CUTOVER=false` puis vider le cache de configuration restaure les routes et écritures legacy. Les jobs sont idempotents et peuvent être rejoués.

## Retour opérationnel après cutover

Après la première écriture canonique, conserver `LEGACY_FLOW_CANONICAL_IS_AUTHORITATIVE=true`, même si `LEGACY_FLOW_CUTOVER` est temporairement désactivé pendant un rollback applicatif. Les anciennes URL continuent alors de lire la source canonique et toutes les écritures legacy restent bloquées; aucune donnée périmée n’est réexposée. Un retour complet vers les tables legacy exige une procédure de resynchronisation dédiée validée sur une copie de production, et ne doit jamais être simulé par le seul feature flag.

## Observation et retrait

Conserver au minimum 14 jours de métriques stables. `LEGACY_FLOW_MINIMUM_OBSERVATION_DAYS` peut imposer une durée supérieure, mais ne peut pas réduire ce plancher. Exécuter au moins toutes les 36 heures :

```shell
php artisan legacy:shadow-compare --allow-draft-exclusions --record
```

Chaque exécution produit une observation append-only. La période effective commence à la date la plus récente entre `LEGACY_FLOW_OBSERVATION_STARTED_AT` et le marqueur canonique persistant; antidater la variable ne raccourcit donc jamais l’observation. Après cette période :

1. Prendre une sauvegarde cohérente des tables legacy.
2. Restaurer cette sauvegarde dans un environnement isolé et vérifier son intégrité.
3. Produire un rapport JSON conservé avec le format suivant :

```json
{
  "environment": "isolated-restore",
  "restore_target": "poolbbc-restore-2026-08-01",
  "backup_sha256": "0000000000000000000000000000000000000000000000000000000000000000",
  "integrity_checks": {
    "schema": true,
    "row_counts": true,
    "application_smoke": true
  }
}
```

4. Attester le rapport : `php artisan legacy:attest-backup-restore chemin/rapport.json --attested-by="Identité opérateur"`. Le contenu, son empreinte, l’opérateur et la date sont enregistrés dans un registre append-only.
5. Relancer `php artisan legacy:shadow-compare --allow-draft-exclusions --record`; le code de sortie doit être `0`.
6. Exécuter `php artisan legacy:retirement-readiness`. La commande exige l’autorité canonique, 14 jours réels au minimum, une couverture shadow stable sans intervalle supérieur à 36 heures, un shadow courant cohérent et une attestation de restauration postérieure à l’observation.

Après ce feu vert seulement :

- archiver `weeks`, `week_phases`, `predictions`, `prediction_scores`, `week_outcomes`, `season_predictions` et `season_prediction_scores`;
- préparer une migration forward-only séparée;
- retirer dans une MR dédiée les composants, modèles et hooks `Schema::hasColumn` legacy;
- réexécuter la suite complète et le shadow report sur une copie de production.

Le retrait est volontairement séparé : supprimer les tables avant la fin de l’observation rendrait le rollback promis par le feature flag impossible.
