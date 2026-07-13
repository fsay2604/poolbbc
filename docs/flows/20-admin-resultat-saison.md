# Administrateur — enregistrer le résultat de saison

**Acteur** : administrateur.

## Parcours

1. Depuis la saison active, ouvrir « Résultat de la saison ».
2. Choisir gagnant, premier éliminé et six membres distincts du Top 6.
3. Enregistrer les champs sur la saison.
4. Recalculer toutes les prédictions et scores sans recalculer le résultat depuis les semaines.
5. Invalider les statistiques du tableau de bord et afficher le succès.

## Règles

- Les huit choix sont obligatoires.
- Le premier éliminé ne peut être gagnant ni membre du Top 6.
- Un candidat inactif déjà sélectionné reste disponible lors d’une correction.

## Écarts UX et intégrité observés

- Huit listes indépendantes rendent les doublons faciles; aucune interaction de type classement/glisser-déposer ou exclusion visuelle.
- L’enregistrement déclenche un recalcul complet synchrone sans état de progression.
- Le formulaire ne précise pas que cette saisie manuelle peut diverger du résultat dérivé des semaines.
- Les prédictions de saison non confirmées sont incluses dans le scoring.
- Aucune date de publication ou de verrouillage n’encadre les prédictions de saison.

## Sources et couverture

- `resources/views/livewire/admin/seasons/⚡outcome/`.
- `app/Actions/Predictions/ScoreSeasonPredictions.php`.
- `tests/Feature/AdminSeasonOutcomeTest.php`, `AdminSeasonOutcomeRecalculateTest.php`.

