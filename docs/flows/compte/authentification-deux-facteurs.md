# Authentification à deux facteurs

## Acteur

- Utilisateur authentifié qui active, consulte ou désactive sa protection A2F.

## Préconditions et autorisations

- La fonctionnalité A2F doit être activée dans Fortify.
- La route `/settings/two-factor` exige une session authentifiée.
- La configuration actuelle exige une confirmation récente du mot de passe avant d’ouvrir cette route.

## Parcours

1. L’utilisateur confirme son mot de passe si Fortify le demande, puis ouvre les paramètres A2F.
2. Il choisit d’activer la protection; Fortify génère le secret et les codes de récupération.
3. Le composant affiche un code QR ainsi qu’une clé de configuration manuelle.
4. L’utilisateur configure son application TOTP puis saisit le code à six chiffres produit.
5. Après confirmation, l’A2F est active et les codes de récupération peuvent être affichés ou régénérés.
6. L’utilisateur peut ensuite désactiver l’A2F depuis le même écran.

## Règles et états terminaux

- La configuration actuelle exige de confirmer le premier code TOTP avant de considérer l’A2F comme active.
- Une activation abandonnée avant cette confirmation est nettoyée au prochain montage du composant: secret et codes non confirmés sont supprimés.
- Le code de confirmation est obligatoire, textuel et long de six caractères.
- Régénérer les codes de récupération invalide l’ensemble précédent.
- Chaque code de récupération est à usage unique lors du [challenge de connexion](../acces/challenge-connexion-a2f.md).
- La désactivation supprime la protection A2F et rend inutilisables son secret et ses codes.

## Limites vérifiées

- Si le code QR ou la clé ne peuvent pas être chargés, le composant vide ces données, affiche une erreur et empêche de poursuivre la configuration.
- Si les codes de récupération ne peuvent pas être déchiffrés, la liste reste vide et une erreur est affichée.
- La désactivation et la régénération sont immédiates, sans deuxième dialogue de confirmation.
- Les tests couvrent l’accès protégé par confirmation du mot de passe, le refus lorsque la fonctionnalité est désactivée et le nettoyage d’une activation abandonnée; ils ne couvrent pas le cycle complet d’activation ni la régénération.

## Sources et couverture

- `routes/web.php`
- `config/fortify.php`
- `app/Http/Requests/Settings/TwoFactorCodeRequest.php`
- `resources/views/livewire/settings/⚡two-factor/two-factor.php`
- `resources/views/livewire/settings/⚡two-factor/two-factor.blade.php`
- `resources/views/livewire/settings/two-factor/⚡recovery-codes/recovery-codes.php`
- `resources/views/livewire/settings/two-factor/⚡recovery-codes/recovery-codes.blade.php`
- `tests/Feature/Settings/TwoFactorAuthenticationTest.php`
