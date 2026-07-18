<?php

namespace App\Providers;

use App\Listeners\PreventCanonicalMigrationRollback;
use App\Models\User;
use App\Translation\PhpJsonFileLoader;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('translation.loader', function ($loader, $app) {
            if (! $loader instanceof FileLoader) {
                return $loader;
            }

            $custom = new PhpJsonFileLoader($app['files'], $loader->paths());

            foreach ($loader->jsonPaths() as $path) {
                $custom->addJsonPath($path);
            }

            foreach ($loader->namespaces() as $namespace => $hint) {
                $custom->addNamespace($namespace, $hint);
            }

            return $custom;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MigrationStarted::class, PreventCanonicalMigrationRollback::class);
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);
    }
}
