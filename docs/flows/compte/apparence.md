# Apparence

## Acteur

- Utilisateur authentifié qui choisit le thème visuel de l’application dans son navigateur courant.

## Préconditions et autorisations

- La route `/settings/appearance` exige une session authentifiée.
- Le navigateur doit autoriser JavaScript et le stockage local pour appliquer et mémoriser le choix.

## Parcours

1. L’utilisateur ouvre Paramètres, puis Apparence.
2. Il choisit `Clair`, `Sombre` ou `Système` dans le groupe segmenté.
3. Flux applique immédiatement le thème au document.
4. Pour `Clair` ou `Sombre`, Flux enregistre `flux.appearance` dans le stockage local.
5. Pour `Système`, Flux retire cette valeur et suit la préférence de couleur du système d’exploitation.

## Règles et états terminaux

- Le choix est géré entièrement côté navigateur par `$flux.appearance`.
- Le mode `Système` réagit aux changements de la préférence système.
- Le thème sélectionné est réappliqué pendant les navigations Livewire.
- Aucune donnée d’apparence n’est enregistrée sur le modèle `User` ni envoyée par le composant Livewire.

## Limites vérifiées

- La préférence est propre au navigateur et ne suit pas le compte entre plusieurs appareils ou profils de navigateur.
- Sans stockage local, le choix explicite ne persiste pas après la session de navigation.
- Aucun test applicatif automatisé ne couvre actuellement les trois choix ou leur persistance; le comportement repose sur Flux UI.

## Sources et couverture

- `routes/web.php`
- `resources/views/livewire/settings/⚡appearance/appearance.php`
- `resources/views/livewire/settings/⚡appearance/appearance.blade.php`
- `vendor/livewire/flux/src/AssetManager.php`
