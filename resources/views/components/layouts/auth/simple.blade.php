<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body id="auth-simple" class="min-h-screen antialiased flex justify-center items-center gap-4 p-4 dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
            <div class="flex w-full max-w-sm flex-col gap-8">
                <a href="{{ route('home') }}" class="flex w-full flex-col items-center gap-2 font-medium" wire:navigate.hover>
                    <span class="flex w-full items-center justify-center">
                        <img
                            src="{{ asset('storage/images/logo.png') }}"
                            alt="{{ config('app.name', 'Laravel') }}"
                            class="w-full object-contain"
                        />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        @fluxScripts
    </body>
</html>
