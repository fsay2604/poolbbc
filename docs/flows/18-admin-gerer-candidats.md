# Administrateur — gérer les candidats

**Acteur** : administrateur.

**Précondition** : une saison active.

## Parcours

1. Créer ou sélectionner une carte de candidat.
2. Modifier nom, sexe, professions, avatar, statut actif et ordre.
3. Enregistrer; l’ancien avatar est supprimé si un nouveau est téléversé.
4. Invalider les statistiques du tableau de bord.
5. Pour supprimer, ouvrir un dialogue puis confirmer la suppression définitive.

## Écarts UX et intégrité observés

- Les cartes ont `role="button"` et `tabindex="0"`, mais aucune activation clavier Enter/Espace.
- Le formulaire ne signale pas clairement le mode création ou édition et n’offre pas de bouton Réinitialiser/Annuler.
- La suppression définitive peut laisser des identifiants dans les payloads JSON des prédictions et résultats; l’historique affichera alors `--`.
- Le message de succès de suppression est commenté dans la vue.
- Les opérations fichier/base ne sont pas atomiques et les types MIME permis ne sont pas explicitement limités.
- Corriger un résultat d’éviction ne réactive pas automatiquement un candidat précédemment désactivé.

## Sources et couverture

- `resources/views/livewire/admin/houseguests/⚡index/`.
- `app/Http/Requests/Admin/SaveHouseguestRequest.php`.
- `tests/Feature/AdminHouseguestAvatarTest.php`, `AdminHouseguestDeleteTest.php`.

