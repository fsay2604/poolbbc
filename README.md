# Pool BBC — Système

Ce dépôt contient une application Laravel 12 basée sur le **starter kit Livewire**. L’interface est construite avec **Livewire / Volt** et les assets sont compilés via **Vite**. L’authentification est fournie par **Laravel Fortify**.

## Stack technique

- **Backend** : Laravel 12 (PHP 8.2+).
- **UI** : Livewire (Volt) + Flux UI.
- **Build front** : Vite.
- **Tests** : Pest.

## Organisation du code

- `app/` : logique métier (Controllers, Models, Services, etc.).
- `routes/` : routes web/API.
- `resources/` : vues Livewire/Blade et assets front.
- `database/` : migrations, factories et seeders.
- `config/` : configuration Laravel.

## Démarrage rapide

1. Installer les dépendances PHP/JS :
   ```bash
   composer install
   npm install
   ```
2. Préparer l’environnement :
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   ```
3. Lancer l’environnement de dev :
   ```bash
   composer run dev
   ```

## Scripts utiles

- **Build assets** : `npm run build`
- **Dev** : `composer run dev`
- **Tests** : `composer run test`

## Notes

- Le script `composer run dev` lance simultanément le serveur Laravel, la queue et Vite.
- Pour un setup complet automatisé, utilisez `composer run setup`.
