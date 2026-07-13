# Administrateur — corriger une prédiction

**Acteur** : administrateur.

**Entrée actuelle** : URL directe `/admin/predictions/{prediction}`; aucun lien vers cette route n’est présent dans les écrans.

## Parcours

1. Résoudre la prédiction, son participant, sa semaine et ses phases.
2. Modifier les choix par phase et éventuellement `confirmed_at`.
3. Enregistrer le payload.
4. Inscrire l’administrateur, la date de correction et incrémenter le compteur d’éditions.
5. Afficher le succès.

## Écarts UX et intégrité observés

- Le parcours est pratiquement introuvable depuis l’interface.
- Aucun motif de correction n’est demandé; l’audit ne conserve que le dernier administrateur et un compteur, pas l’avant/après.
- La sauvegarde ne recalcule pas le score et n’invalide pas le cache; le succès peut donc coexister avec un classement périmé.
- Le composant n’offre pas de bouton Retour vers le participant ou la semaine.
- Un administrateur peut modifier la date de confirmation sans avertissement sur l’impact d’équité.

## Sources et couverture

- `routes/web.php`, route `admin.predictions.edit`.
- `resources/views/livewire/admin/predictions/⚡edit/`.
- `tests/Feature/WeeklyPredictionPhasesIndependentTest.php`.

