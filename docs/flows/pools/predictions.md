# Saisir et visualiser les prédictions d’un pool

## Acteur

Membre actif d’un pool qui utilise les prédictions, que le pool soit en mode prédictions seulement ou hybride.

## Préconditions et autorisations

- La route `pools.predictions` exige que l’utilisateur puisse voir le pool et que le mode du pool accepte les prédictions.
- La page réunit deux sources distinctes : les événements **de la saison officielle** projetés et activés dans le pool, puis les événements **propres au pool** créés dans ses rondes locales.
- Dans les deux sources, seuls les événements configurés en mode prédiction ou hybride participent au parcours. Les événements réservés aux équipes sont exclus.
- La page ne donne aucun droit de gestion sur la structure ou les résultats. Ces opérations se trouvent respectivement dans `pools.events` et `pools.results`.

## Parcours

1. Ouvrir **Prédictions** et consulter une progression unique couvrant les deux sources.
2. Parcourir les événements regroupés par ronde. Le badge **Saison officielle** ou **Propre au pool** indique l’origine de chaque ronde.
3. Choisir une ou plusieurs options selon les limites de l’événement. Chaque modification enregistre automatiquement un brouillon.
4. Soumettre explicitement la réponse avant l’échéance, puis la mettre à jour tant que l’événement demeure ouvert.
5. Repérer les événements à faire, en brouillon, soumis, verrouillés ou manqués dans la même progression.
6. Après la règle de révélation applicable, consulter les réponses soumises des autres membres et le résultat publié sur la carte de l’événement.

## Règles et états terminaux

- États persistés d’une prédiction : `draft`, `submitted`, puis `locked` au verrouillage. L’interface traduit aussi l’absence de réponse et les échéances en **À faire**, **Brouillon**, **Soumise**, **Verrouillée**, **Brouillon expiré**, **Non soumise** ou **Annulée**.
- Un brouillon peut être incomplet; une soumission doit respecter le minimum et le maximum. Réduire une réponse soumise sous le minimum la remet en brouillon.
- Une option explicite « aucun candidat » ne peut être combinée avec aucune autre option.
- Un brouillon encore présent à l’échéance expire et n’est jamais promu automatiquement en réponse soumise.
- Pour une association officielle configurée `after_lock`, les réponses concurrentes soumises sont révélées lorsque l’événement est verrouillé ou possède un résultat saisi. Avec `after_publish`, elles restent cachées jusqu’à la publication officielle.
- Pour un événement propre au pool, les réponses concurrentes soumises sont révélées après le verrouillage, lors de la saisie du résultat ou après sa publication.
- La propre réponse du membre demeure visible. Les brouillons des autres membres ne sont jamais chargés ni révélés.

## Limites vérifiées

- Le serveur resynchronise l’état et l’échéance dans la transaction : une soumission provenant d’une page périmée est refusée avec une erreur contrôlée après la fermeture.
- Un membre retiré, un pool terminé ou archivé, une association officielle inactive, un événement étranger au pool ou à sa saison, ou un mode équipes seulement refuse toute écriture.
- Les options doivent appartenir à l’événement ciblé. Les identifiants en double sont normalisés avant validation.
- Chaque état Livewire porte une clé de source explicite (`official-{id}` ou `local-{id}`). Un événement officiel et un événement local qui partagent le même identifiant numérique ne peuvent donc ni afficher ni enregistrer la réponse de l’autre.
- Les lectures des réponses révélées sont regroupées par source et ne croissent pas d’une requête par événement. Avant la révélation, seule la réponse du membre courant est hydratée.
- Le compteur et la prochaine échéance de la vue d’ensemble combinent les événements officiels et locaux admissibles.

## Sources et couverture

- `resources/views/components/pools/⚡predictions/predictions.php`
- `resources/views/components/pools/⚡predictions/predictions.blade.php`
- `app/Support/PredictionEventView.php`
- `app/Actions/Predictions/SubmitPoolEventPrediction.php`
- `app/Actions/Predictions/SubmitEventPrediction.php`
- `app/Models/PoolEventPrediction.php`
- `app/Models/EventPrediction.php`
- `tests/Feature/UnifiedPoolPredictionsTest.php`
- `tests/Feature/PoolFirstNavigationTest.php`
- `tests/Feature/EventLifecycleAndPoolModeTest.php`
