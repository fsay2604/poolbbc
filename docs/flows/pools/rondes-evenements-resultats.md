# Gérer les rondes et événements locaux d’un pool

## Acteur

Membre actif pour répondre aux événements locaux; propriétaire ou administrateur actif du pool pour gérer les rondes, événements locaux et adaptations officielles propres au pool.

## Préconditions et autorisations

- La page `pools.events`, affichée comme **Événements du pool**, est centrée sur les rondes et événements créés exclusivement dans le pool.
- Les prédictions officielles se saisissent dans `pools.predictions`; seules les prédictions locales se saisissent ici.
- Un bloc compact conserve les adaptations des événements officiels propres au pool : suivi de participation caché et barème modifiable avant son gel. Les réponses et résultats officiels se consultent dans `pools.predictions`.
- Pour un administrateur, les menus **Rondes et événements** et **Résultats officiels** se trouvent dans la navigation du pool et utilisent respectivement `pools.official-rounds` et `pools.official-results`.

## Parcours

1. En gestion, adapter au besoin le mode, la visibilité, les limites de réponse et le barème d’une association officielle avant sa première réponse ou son verrouillage.
2. Ouvrir la modale **Nouvelle ronde**, puis créer une ronde locale sans quitter le calendrier.
3. Ouvrir la modale **Nouvel événement** et configurer son mode, sa source de choix, ses échéances, ses limites de sélection et son barème.
4. Tant que toute la ronde est en brouillon et sans réponse ni résultat, réordonner ses événements.
5. Ouvrir l’événement local; les membres enregistrent un brouillon ou soumettent leur réponse, puis l’événement se verrouille à l’échéance ou sur commande.
6. Prévisualiser les points d’un résultat local, puis l’enregistrer ou le publier selon son mode de publication.
7. Pour corriger un résultat publié, fournir une justification et publier une nouvelle version.

## Règles et états terminaux

- Cycle local: `draft` → `open` → `locked` → `result_entered` → `published`; un événement en brouillon, ouvert ou verrouillé peut aussi devenir `cancelled` avec une raison.
- Les échéances effectives ouvrent et verrouillent l’événement même si la page affichait encore un ancien état; seules les réponses soumises deviennent `locked`.
- En publication immédiate, résultat et points sont publiés dans la même transaction. En publication manuelle, un brouillon privé et non pointé est d’abord enregistré, peut être modifié, puis doit être publié explicitement.
- Un résultat publié n’est jamais modifié ni supprimé. Une correction crée une version supérieure, ajoute des écritures inversant les anciens points, puis publie les nouveaux points.
- Une annulation ne crée aucun point. Un événement avec un résultat en attente de publication ne peut pas être annulé.
- Les réponses locales concurrentes sont révélées après verrouillage. Les réponses officielles suivent la visibilité `after_lock` ou `after_publish` dans `pools.predictions`; la page locale n’en duplique plus le contenu ni l’historique.

## Limites vérifiées

- Le mode d’événement doit être compatible avec le mode du pool; les heures, limites de sélection, options et barèmes sont validés.
- Les règles d’une association officielle restent modifiables lorsque l’événement est en brouillon ou ouvert, tant qu’aucune réponse ni écriture de points n’existe; elles se figent au plus tard au verrouillage. Les règles d’un événement local sont fixées à sa création et ses options admissibles sont figées à son ouverture.
- Publier un résultat local exige un aperçu correspondant exactement à la sélection courante; modifier la sélection invalide l’aperçu.
- Les brouillons de prédiction ne sont pas pointés. L’option « aucun candidat » est mutuellement exclusive.
- Un pool ne peut être terminé avant que tous ses événements locaux et officiels actifs soient publiés ou annulés.

## Sources et couverture

- `resources/views/components/pools/⚡events/events.php`
- `app/Actions/Events/CreateEvent.php`
- `app/Actions/Events/SynchronizeEventLifecycle.php`
- `app/Actions/Events/UpdatePoolEventRules.php`
- `app/Actions/Scoring/PreviewEventScore.php`
- `app/Actions/Scoring/PublishEventResult.php`
- `tests/Feature/EventLifecycleAndPoolModeTest.php`
- `tests/Feature/EventScoringFlowTest.php`
- `tests/Feature/ResultPublicationFlowTest.php`
- `tests/Feature/ResultDraftAmendmentTest.php`
