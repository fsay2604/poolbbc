# Créer ou rejoindre un pool

## Acteur

Utilisateur authentifié; tout utilisateur peut créer un pool ou accepter une invitation valide.

## Préconditions et autorisations

- La page `pools.index` est protégée par `auth`.
- La création exige une saison existante, un nom et un mode de compétition: prédictions seulement, équipes seulement ou hybride.
- Une invitation ne peut être acceptée que pendant l’état `registration`, avant la fermeture des inscriptions et tant que le pool n’est pas plein.

## Parcours

### Créer

1. Choisir la saison, le nom, la description facultative, le mode et la capacité.
2. Pour un mode avec équipes, choisir le nombre de candidats par membre, l’ordre linéaire ou serpent et le caractère exclusif ou non du repêchage.
3. Conserver le barème officiel standard ou fournir les adaptations de pointage proposées.
4. Créer le pool; son créateur devient propriétaire et premier membre actif.

### Rejoindre

1. Saisir le code d’invitation de huit caractères.
2. Consulter l’aperçu des règles, de la saison, de la capacité et du mode du pool.
3. Accepter explicitement l’invitation sans modifier le code entre l’aperçu et la confirmation.
4. Retrouver le pool dans la liste des adhésions actives.

## Règles et états terminaux

- Un nouveau pool demeure en `configuration`; son gestionnaire doit confirmer les règles et ouvrir les inscriptions.
- Les modes équipes seulement et hybride créent un repêchage `pending`; le mode prédictions seulement n’en crée pas.
- Les événements officiels déjà associés à la saison sont synchronisés vers le nouveau pool avec ses règles de pointage.
- Rejoindre de nouveau un pool dont on est déjà membre actif retourne l’adhésion existante sans doublon.
- Le code est normalisé en majuscules. Un membre retiré ne peut ni prévisualiser ni réutiliser l’invitation.

## Limites vérifiées

- La capacité doit être comprise entre 1 et 50 membres; le nombre de choix par membre, entre 1 et 20.
- Les valeurs de pointage sont bornées, les pénalités de mauvaise réponse ne peuvent être positives et le mode doit être compatible avec le pool.
- Une invitation invalide, fermée ou complète produit une erreur contrôlée; la transaction verrouille le pool avant d’ajouter le membre.
- Les paramètres structurants du pool ne peuvent plus changer après le début du repêchage ou l’activation.

## Sources et couverture

- `resources/views/components/pools/⚡index/index.php`
- `app/Actions/Pools/CreatePool.php`
- `app/Actions/Pools/PreviewPoolInvitation.php`
- `app/Actions/Pools/JoinPool.php`
- `app/Http/Requests/Pools/CreatePoolRequest.php`
- `tests/Feature/PoolFlowTest.php`
- `tests/Feature/EventLifecycleAndPoolModeTest.php`
