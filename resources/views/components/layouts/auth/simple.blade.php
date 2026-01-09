<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div style="background-image: url('{{ asset('storage/images/background.png') }}');"
            class="flex min-h-[100dvh] w-full flex-col items-center justify-center gap-6 bg-cover bg-center bg-no-repeat p-4 md:p-8 md:pt-0"
        >
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex w-full flex-col items-center gap-2 font-medium" wire:navigate>
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
        </div>
        @fluxScripts
    </body>
</html>
