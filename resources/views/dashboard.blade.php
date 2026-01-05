<x-layouts.app :title="__('Dashboard')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Houseguests') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
            @endif
        </div>

        <div class="grid gap-3 grid-cols-2 sm:grid-cols-4 md:grid-cols-8">
            @foreach ($houseguests as $houseguest)
                <x-houseguest-card :houseguest="$houseguest" />
            @endforeach
        </div>
    </div>
</x-layouts.app>
