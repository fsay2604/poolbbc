# Consulter le tableau de bord des pools

## Acteur

Utilisateur authentifié.

## Préconditions et autorisations

- La route `dashboard` déclare les middlewares `auth` et `verified`.
- Dans l’état actuel, `User` n’implémente pas `MustVerifyEmail`; le middleware `verified` ne bloque donc pas un compte non vérifié.
- Un pool apparaît seulement si l’utilisateur y possède une adhésion active; être propriétaire sans adhésion active ne suffit pas.
- Un visiteur non authentifié est redirigé vers la connexion.

## Parcours

1. Ouvrir `/dashboard`.
2. Consulter une carte par pool accessible, avec sa saison, son état, le pointage personnel et le nombre de membres actifs.
3. Sélectionner une carte pour ouvrir la vue d’ensemble de ce pool, ou utiliser « Accéder à mes pools » pour créer ou rejoindre un pool.
4. En l’absence de pool, suivre l’état vide vers la page de création et d’invitation.

## Règles et états terminaux

- Le pointage personnel est la somme des écritures **publiées** du registre de points de l’adhésion courante.
- Les pools terminés ou archivés restent affichés tant que l’adhésion demeure active; leur badge indique leur état historique.
- Le tableau de bord ne calcule aucun classement global: chaque carte mène au contexte isolé de son pool.

## Limites vérifiées

- Les membres retirés ne voient plus le pool au prochain chargement.
- Les écritures en attente, les brouillons de résultats et les résultats officiels dont la publication a échoué ne contribuent pas au total affiché.
- Le tableau de bord présente un aperçu; les prédictions, résultats détaillés et mouvements de points se consultent dans les pages du pool.

## Sources et couverture

- `routes/web.php`
- `app/Actions/Dashboard/BuildDashboardStats.php`
- `resources/views/dashboard.blade.php`
- `tests/Feature/DashboardTest.php`
