<x-layouts.app :title="__('Dashboard')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <flux:card class="bg-accent/5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg">Vos pools privés Big Brother</flux:heading>
                    <flux:text class="mt-1">Créez un pool, invitez vos proches, repêchez vos célébrités et suivez le classement.</flux:text>
                </div>
                <flux:button :href="route('pools.index')" variant="primary" icon="user-group" wire:navigate.hover>Accéder à mes pools</flux:button>
            </div>
        </flux:card>

        <div class="flex flex-col gap-1">
            <flux:heading size="xl" level="1">Aperçu de vos pools</flux:heading>
            <flux:text>Consultez le pointage et les membres de chacun de vos pools.</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($pools as $pool)
                @php($currentMember = $pool->activeMembers->first())
                <a href="{{ route('pools.show', $pool) }}" wire:navigate.hover class="group rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                    <flux:card class="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-lg">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:heading size="lg" class="truncate">{{ $pool->name }}</flux:heading>
                                <flux:text class="mt-1 truncate text-sm text-zinc-500">{{ $pool->season->name }}</flux:text>
                            </div>
                            <flux:badge color="zinc">
                                {{ $pool->status->label() }}
                            </flux:badge>
                        </div>
                        <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                <div class="text-zinc-500">Votre pointage</div>
                                <div class="mt-1 font-semibold tabular-nums">{{ (int) ($currentMember?->point_entries_sum_points ?? 0) }} pts</div>
                            </div>
                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                <div class="text-zinc-500">Membres</div>
                                <div class="mt-1 font-semibold tabular-nums">{{ $pool->active_members_count }} / {{ $pool->max_members }}</div>
                            </div>
                        </div>
                    </flux:card>
                </a>
            @empty
                <flux:card class="sm:col-span-2 xl:col-span-3">
                    <div class="py-8 text-center">
                        <flux:heading size="lg">Aucun pool actif</flux:heading>
                        <flux:text class="mt-2">Créez un pool ou rejoignez-en un avec un code d’invitation.</flux:text>
                        <flux:button class="mt-4" :href="route('pools.index')" variant="primary" wire:navigate.hover>Commencer</flux:button>
                    </div>
                </flux:card>
            @endforelse
        </div>
    </div>
</x-layouts.app>
