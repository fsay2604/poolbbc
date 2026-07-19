# Soumettre les prédictions officielles d’un pool

## Acteur

Membre actif d’un pool en mode prédictions seulement ou hybride.

## Préconditions et autorisations

- La route `pools.predictions` n’existe fonctionnellement que pour un pool qui utilise les prédictions; l’utilisateur doit aussi pouvoir voir ce pool.
- Seuls les événements **officiels** actifs, liés à la saison du pool et configurés en mode prédiction ou hybride apparaissent ici.
- Les prédictions d’événements locaux ne figurent pas sur cette page: elles sont saisies dans `pools.events`.

## Parcours

1. Ouvrir « Prédictions » et parcourir les événements officiels regroupés par ronde.
2. Choisir une ou plusieurs options selon les limites propres à l’événement; chaque modification enregistre automatiquement un brouillon.
3. Soumettre explicitement la réponse avant l’échéance, puis la mettre à jour tant que l’événement reste ouvert.
4. Suivre la progression soumise, les réponses manquantes et l’état de chaque prédiction.
5. Après la règle de révélation applicable, consulter les réponses soumises des autres membres et, après publication, le résultat officiel.

## Règles et états terminaux

- États d’une prédiction: `draft`, `submitted`, puis `locked` au verrouillage de l’événement.
- Un brouillon peut être incomplet; une soumission doit respecter le minimum et le maximum. Réduire une réponse soumise sous le minimum la remet en brouillon.
- Une option explicite « aucun candidat » ne peut être combinée avec une autre option.
- Un brouillon encore présent à l’échéance devient expiré et n’est jamais promu automatiquement en réponse soumise.
- Avec la visibilité `after_lock`, les réponses concurrentes soumises sont révélées au verrouillage; avec `after_publish`, elles restent cachées jusqu’à la publication du résultat.
- La propre réponse du membre demeure visible; les brouillons des autres membres ne sont jamais chargés ni révélés.

## Limites vérifiées

- Le serveur resynchronise l’état et l’échéance dans la transaction: une soumission provenant d’une page périmée est refusée proprement après la fermeture.
- Un membre retiré, un pool terminé ou archivé, un événement inactif ou un mode équipes seulement refuse toute écriture.
- Les options doivent appartenir à l’événement officiel et les identifiants en double sont normalisés.
- Le compteur de progression ignore les événements réservés au pointage des équipes.
- Cette page ne crée ni ronde, ni événement, ni résultat; ces responsabilités appartiennent aux gestionnaires du pool ou aux administrateurs officiels.

## Sources et couverture

- `resources/views/components/pools/⚡predictions/predictions.php`
- `app/Actions/Predictions/SubmitPoolEventPrediction.php`
- `app/Models/PoolEventPrediction.php`
- `tests/Feature/PoolFirstNavigationTest.php`
- `tests/Feature/EventLifecycleAndPoolModeTest.php`
- `tests/Feature/CanonicalEventDomainTest.php`
