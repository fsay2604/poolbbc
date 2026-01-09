# Pool BBC — System

This repository contains a Laravel 12 application based on the **Livewire starter kit**. The UI is built with **Livewire / Volt** and assets are compiled with **Vite**. Authentication is provided by **Laravel Fortify**.

## Tech stack

- **Backend**: Laravel 12 (PHP 8.2+).
- **UI**: Livewire (Volt) + Flux UI.
- **Frontend build**: Vite.
- **Tests**: Pest.

## Code organization

- `app/`: business logic (Controllers, Models, Services, etc.).
- `routes/`: web/API routes.
- `resources/`: Livewire/Blade views and frontend assets.
- `database/`: migrations, factories, and seeders.
- `config/`: Laravel configuration.

## Quick start

1. Install PHP/JS dependencies:
   ```bash
   composer install
   npm install
   ```
2. Prepare the environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   ```
3. Start the dev environment:
   ```bash
   composer run dev
   ```

## Useful scripts

- **Build assets**: `npm run build`
- **Dev**: `composer run dev`
- **Tests**: `composer run test`

## Notes

- `composer run dev` starts the Laravel server, queue worker, and Vite together.
- For a complete automated setup, use `composer run setup`.
