
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Repêchage · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">{{ $pool->draft_mode->value === 'snake' ? 'Ordre serpent' : 'Ordre linéaire' }} · {{ $pool->picks_per_member }} choix par membre</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-action-message on="draft-pick-made">Votre choix est confirmé et ne peut plus être modifié.</x-action-message>

        <flux:card class="overflow-hidden">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">État : {{ $draft->status->value }}</flux:text>
                    @if ($draft->currentMember)
                        <flux:heading size="lg" class="mt-1">Au tour de {{ $draft->currentMember->user->name }}</flux:heading>
                    @else
                        <flux:heading size="lg" class="mt-1">Repêchage terminé</flux:heading>
                    @endif
                </div>
                <div class="rounded-xl bg-accent/10 px-4 py-3 text-center">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Choix courant</div>
                    <div class="text-2xl font-bold tabular-nums">{{ $draft->current_pick_number }}</div>
                </div>
            </div>
        </flux:card>

        @if ($canPick)
            <div>
                <flux:heading size="lg">Votre sélection</flux:heading>
                <flux:text class="mt-1 text-sm">Choisissez une célébrité disponible. La sélection est définitive.</flux:text>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-7">
                    @foreach ($availableHouseguests as $houseguest)
                        <flux:card class="flex flex-col gap-3 p-3" wire:key="available-{{ $houseguest->id }}">
                            <div class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                @if ($houseguest->avatar_url)
                                    <img src="{{ $houseguest->avatar_url }}" alt="{{ $houseguest->name }}" class="size-full object-cover" />
                                @endif
                            </div>
                            <div class="min-w-0 truncate text-sm font-medium">{{ $houseguest->name }}</div>
                            <flux:button size="sm" variant="primary" wire:click="pick({{ $houseguest->id }})" class="w-full">Choisir</flux:button>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <flux:heading size="lg">Équipes</flux:heading>
            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($members as $member)
                    <flux:card wire:key="roster-{{ $member->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <flux:heading>{{ $member->user->name }}</flux:heading>
                            <flux:badge color="zinc">Position {{ $member->draft_position }}</flux:badge>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @forelse ($draft->picks->where('pool_member_id', $member->id) as $pick)
                                <flux:badge color="blue">{{ $pick->houseguest->name }}</flux:badge>
                            @empty
                                <flux:text class="text-sm text-zinc-500">Aucun choix encore.</flux:text>
                            @endforelse
                        </div>
                    </flux:card>
                @endforeach
            </div>
        </div>
    </div>
</section>
