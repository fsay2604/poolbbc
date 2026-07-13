# Administrateur — configurer les semaines et leurs phases

**Acteur** : administrateur.

**Précondition** : une saison active.

## Parcours

1. Ouvrir Administration > Semaines.
2. Créer une semaine proposée verrouillée, ou charger une semaine existante.
3. Configurer numéro, nom, verrouillage, échéance automatique, début et fin.
4. Ajouter, retirer et ordonner les phases HOH, nominations, veto et évictions.
5. Configurer le nombre de sélections demandé par phase.
6. Enregistrer la semaine et synchroniser ses phases.
7. Réconcilier silencieusement toutes les prédictions et le résultat existants avec la nouvelle structure.

## Écarts UX et intégrité observés

- Les lignes dynamiques utilisent l’index comme `wire:key`; après suppression/réordonnancement, Livewire peut réutiliser le mauvais état de champ.
- Modifier des phases réécrit les prédictions confirmées sans aperçu ni consentement.
- Une permutation de positions existantes peut heurter la contrainte unique pendant les mises à jour successives.
- L’ensemble semaine/phases/payloads n’est pas transactionnel.
- Les dates ne sont pas validées chronologiquement et `starts_at`/`ends_at` ne contrôlent pas réellement l’ouverture.
- Aucun recalcul des scores ni invalidation du tableau de bord après la réconciliation.
- L’écran ne montre pas le nombre de brouillons, confirmations ou résultats impactés avant une modification structurelle.

## Sources et couverture

- `resources/views/livewire/admin/weeks/⚡index/`.
- `app/Actions/Weeks/WeekPhaseManager.php`.
- `app/Http/Requests/Admin/SaveWeekRequest.php`.
- `tests/Feature/AdminWeekCanClearAutoLockAtTest.php`, `AdminSeasonCreatesWeeksTest.php`.

