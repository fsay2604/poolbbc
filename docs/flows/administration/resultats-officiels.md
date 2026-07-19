# Administration — Résultats officiels

## Acteur

Administrateur.

## Préconditions et autorisations

- Le compte doit être authentifié et posséder le rôle administrateur.
- Le résultat se gère depuis `admin.official-rounds` (`/admin/official-rounds`).
- L’événement doit être verrouillé, en résultat saisi ou publié; aucun aperçu n’est disponible avant son verrouillage effectif.

## Parcours

1. Ouvrir la saisie du résultat et sélectionner uniquement des options figées de l’événement.
2. Générer l’aperçu en lecture seule pour vérifier, par pool et par membre, les points d’équipe, de prédiction et le total.
3. Confirmer sans modifier la sélection après l’aperçu.
4. En publication immédiate, lancer directement le pointage. En publication manuelle, enregistrer un brouillon, le modifier au besoin après un nouvel aperçu, puis choisir **Publier et lancer le pointage**.
5. Pour corriger un résultat publié, fournir une justification, prévisualiser la nouvelle sélection et publier une nouvelle version.
6. Si une publication reste en attente ou échoue, utiliser **Reprendre le pointage**.

## Règles et états terminaux

- Toute modification de la sélection invalide l’aperçu. La confirmation exige une empreinte correspondant exactement à l’événement et aux options actuellement choisies.
- L’aperçu ne crée ni résultat ni entrée de points.
- Un brouillon manuel reste privé et non pointé. Pendant une correction en brouillon ou en attente, la dernière version publiée demeure la référence visible.
- En mode manuel, la publication suit `draft → pending → published`; en mode immédiat, elle suit `pending → published`. Un traitement peut passer de `pending` à `failed`, puis revenir à `pending` lors d’une reprise.
- Chaque correction crée une version qui référence la précédente. Une version publiée est immuable et ne peut pas être supprimée; le registre inverse les points de la version remplacée avant d’inscrire les nouveaux.
- La finalisation attend un reçu de pointage complété pour chaque événement de pool actif, puis publie le résultat, reconstruit les projections de classement et l’activité des candidats, et invalide les caches concernés.
- La reprise recrée seulement le travail incomplet et conserve les reçus déjà terminés, ce qui rend le pointage idempotent.

## Limites vérifiées

- Les options doivent appartenir à l’événement et respecter ses minimums et maximums de résultat.
- L’option explicite « aucun » ne peut pas être combinée avec une autre réponse.
- Un seul résultat peut être en brouillon, en attente ou en échec pour un événement donné.
- Une correction d’un résultat publié exige une justification. Un brouillon de correction conserve cette exigence lorsqu’il est modifié.
- Les actions administratives de saisie, modification, mise en file et reprise revérifient leur autorisation. Elles et les traitements de finalisation sont transactionnels et protégés par des verrous.
- Les audits distinguent notamment l’enregistrement, la modification d’un brouillon, le début du pointage, la reprise, la publication et la correction, avec la version, les options et la justification applicables.

## Sources et couverture

- `resources/views/components/admin/⚡official-rounds/official-rounds.php`
- `app/Actions/Scoring/PreviewSeasonEventScore.php`
- `app/Actions/Events/PublishSeasonEventResult.php`
- `app/Jobs/ScoreSeasonEventResultJob.php`
- `app/Jobs/FinalizeSeasonEventResultJob.php`
- `app/Models/SeasonEventResult.php`
- `tests/Feature/OfficialResultPreviewTest.php`
- `tests/Feature/OfficialResultPublicationRecoveryTest.php`
- `tests/Feature/ResultDraftAmendmentTest.php`
- `tests/Feature/ResultPublicationFlowTest.php`
