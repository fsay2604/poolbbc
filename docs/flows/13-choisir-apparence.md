# Choisir l’apparence

**Acteur** : utilisateur authentifié.

## Parcours

1. Ouvrir Paramètres, puis Apparence.
2. Choisir Clair, Sombre ou Système.
3. Flux met à jour immédiatement l’apparence et mémorise la préférence côté navigateur.

## Données

- La préférence ne passe pas par Livewire et n’est pas enregistrée dans le profil serveur.
- Le thème sombre repose sur la classe `.dark` et les variantes Tailwind correspondantes.

## Écarts UX observés

- La préférence ne suit pas l’utilisateur entre ses appareils ou navigateurs.
- Les layouts posent initialement `class="dark"` sur `<html>`; la correction précoce de Flux doit donc empêcher un flash sombre en mode clair.
- Aucun test automatisé ne couvre les trois modes, le contraste ou la persistance.

## Sources et couverture

- `resources/views/livewire/settings/⚡appearance/appearance.blade.php`.
- `resources/views/components/layouts/app/sidebar.blade.php`.
- `resources/css/app.css`.

