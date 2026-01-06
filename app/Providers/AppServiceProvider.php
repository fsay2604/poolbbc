<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);

        $jsonTranslationsPath = lang_path('fr/app.php');

        if (is_file($jsonTranslationsPath)) {
            $lines = require $jsonTranslationsPath;

            if (is_array($lines) && $lines !== []) {
                $prefixed = [];

                foreach ($lines as $key => $value) {
                    if (! is_string($key) || $key === '') {
                        continue;
                    }

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $prefixed['*.'.$key] = $value;
                }

                if ($prefixed !== []) {
                    app('translator')->addLines($prefixed, 'fr', '*');
                }
            }
        }
    }
}
