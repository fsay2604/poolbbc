# Participer au repêchage

## Acteur

Membre actif d’un pool utilisant les équipes; le gestionnaire dispose aussi des commandes d’ordre, d’état et de correction.

## Préconditions et autorisations

- Le gestionnaire ouvre d’abord les inscriptions, inclut chaque membre actif exactement une fois dans l’ordre, puis confirme le démarrage.
- Le repêchage ne démarre que si la saison offre assez de candidats actifs pour chaque équipe et, en mode exclusif, pour tous les choix du pool.
- Seul le membre dont c’est le tour peut confirmer un choix, et uniquement lorsque le repêchage est `active`.

## Parcours

1. Avant le départ, le gestionnaire ordonne manuellement les membres ou génère un ordre aléatoire.
2. Le membre courant filtre les candidats par nom ou par sexe, ouvre la confirmation, puis valide son choix.
3. La transaction enregistre le choix et avance le tour selon l’ordre linéaire ou serpent.
4. Le gestionnaire peut suspendre et reprendre le repêchage.
5. Avant sa complétion, le gestionnaire peut corriger un choix en sélectionnant un remplacement et en donnant une justification.
6. Le dernier choix termine le repêchage et active automatiquement le pool.

## Règles et états terminaux

- États du repêchage: `pending` → `active` ↔ `paused` → `completed`.
- Un même membre ne peut jamais sélectionner deux fois le même candidat dans son équipe.
- En mode non exclusif, deux membres différents peuvent choisir le même candidat; l’interface masque seulement les candidats déjà présents dans l’équipe dont c’est le tour.
- En mode exclusif, un candidat choisi est indisponible pour toutes les autres équipes.
- Une correction applique les mêmes règles d’unicité, exclut le choix corrigé de la comparaison et crée une entrée d’audit avec l’ancien candidat, le nouveau et la raison.
- Un repêchage `completed`, ses choix et leurs associations sont immuables.

## Limites vérifiées

- Le candidat doit être actif et appartenir à la saison du pool.
- L’action reverrouille le repêchage, le pool, le membre et le candidat: une page périmée reçoit une erreur de validation sans créer de choix ni avancer le tour.
- L’index unique `(draft_id, pool_member_id, houseguest_id)` protège aussi l’unicité d’une équipe contre les écritures concurrentes ou directes.
- Une correction vers le même candidat, vers un candidat déjà dans l’équipe ou sans raison est refusée sans modification ni audit.
- L’ordre devient définitif dès le démarrage.

## Sources et couverture

- `resources/views/components/pools/⚡draft/draft.php`
- `app/Actions/Drafts/StartDraft.php`
- `app/Actions/Drafts/MakeDraftPick.php`
- `app/Actions/Drafts/CorrectDraftPick.php`
- `app/Actions/Drafts/SetDraftOrder.php`
- `app/Actions/Drafts/ChangeDraftStatus.php`
- `tests/Feature/DraftFlowTest.php`
