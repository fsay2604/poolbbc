@props(['pool', 'availablePools' => collect()])

@if (request()->boolean('migrated'))
    <flux:callout class="mb-3" icon="arrow-path">Ce parcours a été transféré vers le nouveau flux du pool. Vos données historiques restent conservées.</flux:callout>
@endif

<div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900 sm:flex-row sm:items-center">
    <flux:dropdown position="bottom" align="start">
        <flux:button variant="ghost" icon="arrows-right-left" icon:trailing="chevron-down" class="w-full justify-between sm:w-auto">
            {{ $pool->name }}
        </flux:button>
        <flux:navmenu class="min-w-64">
            @foreach ($availablePools as $availablePool)
                <flux:navmenu.item
                    :href="route('pools.show', $availablePool)"
                    :current="$availablePool->is($pool)"
                    icon="user-group"
                    wire:navigate.hover
                >
                    <div class="min-w-0">
                        <div class="truncate">{{ $availablePool->name }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ $availablePool->season->name }}</div>
                    </div>
                </flux:navmenu.item>
            @endforeach
            <flux:navmenu.item :href="route('pools.index')" icon="plus" wire:navigate.hover>Voir tous les pools</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <div class="min-w-0 flex-1 overflow-x-auto">
        <flux:navbar class="min-w-max">
            <flux:navbar.item :href="route('pools.show', $pool)" :current="request()->routeIs('pools.show')" icon="home" wire:navigate.hover>
                Vue d’ensemble
            </flux:navbar.item>
            @if ($pool->usesDraft())
                <flux:navbar.item :href="route('pools.draft', $pool)" :current="request()->routeIs('pools.draft')" icon="users" wire:navigate.hover>
                    Mon équipe
                </flux:navbar.item>
            @endif
            @if ($pool->usesPredictions())
                <flux:navbar.item :href="route('pools.predictions', $pool)" :current="request()->routeIs('pools.predictions')" icon="check-circle" wire:navigate.hover>
                    Prédictions
                </flux:navbar.item>
            @endif
            <flux:navbar.item :href="route('pools.events', $pool)" :current="request()->routeIs('pools.events')" icon="calendar-days" wire:navigate.hover>
                Résultats
            </flux:navbar.item>
            <flux:navbar.item :href="route('pools.leaderboard', $pool)" :current="request()->routeIs('pools.leaderboard')" icon="trophy" wire:navigate.hover>
                Classement
            </flux:navbar.item>
        </flux:navbar>
    </div>
</div>
