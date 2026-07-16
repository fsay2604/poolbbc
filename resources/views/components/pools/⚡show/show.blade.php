
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl" level="1">{{ $pool->name }}</flux:heading>
                    <flux:badge>{{ ucfirst($pool->status->value) }}</flux:badge>
                </div>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $pool->season->name }} · {{ $pool->timezone }}</flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('pools.draft', $pool)" icon="queue-list" wire:navigate.hover>Repêchage</flux:button>
                <flux:button :href="route('pools.events', $pool)" icon="calendar-days" wire:navigate.hover>Rondes et événements</flux:button>
                <flux:button :href="route('pools.leaderboard', $pool)" icon="trophy" variant="primary" wire:navigate.hover>Classement</flux:button>
            </div>
        </div>

        <x-action-message on="draft-updated">Le repêchage a été mis à jour.</x-action-message>
        <x-action-message on="member-removed">Le membre a été retiré du pool.</x-action-message>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card><flux:text class="text-sm text-zinc-500">Membres</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->members->count() }} / {{ $pool->max_members }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Choix par membre</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->picks_per_member }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Rondes</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->rounds_count }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Événements</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->events_count }}</flux:heading></flux:card>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <flux:card>
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">Membres et ordre du repêchage</flux:heading>
                    <flux:badge color="zinc">{{ $pool->draft_mode->value === 'snake' ? 'Serpent' : 'Linéaire' }}</flux:badge>
                </div>
                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach ($pool->members as $member)
                        <div class="flex items-center gap-3 py-3" wire:key="member-{{ $member->id }}">
                            <div class="flex size-9 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent">{{ $member->draft_position }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $member->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $member->role->value }}</div>
                            </div>
                            @if ($member->user_id === auth()->id())
                                <flux:badge color="blue">Vous</flux:badge>
                            @endif
                            @if ($canManage && $member->role->value !== 'owner')
                                <flux:button size="sm" variant="danger" wire:click="removeMember({{ $member->id }})">Retirer</flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>

            <div class="flex flex-col gap-4">
                <flux:card>
                    <flux:heading size="lg">Invitation</flux:heading>
                    <flux:text class="mt-2 text-sm">Partagez ce code avant la fermeture des inscriptions.</flux:text>
                    <div class="mt-4 rounded-xl bg-zinc-100 px-4 py-3 text-center font-mono text-2xl font-bold tracking-[0.25em] dark:bg-zinc-800">{{ $pool->invite_code }}</div>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">Repêchage</flux:heading>
                    <flux:text class="mt-2 text-sm">État actuel : {{ $pool->draft->status->value }}</flux:text>
                    @if ($pool->draft->currentMember)
                        <div class="mt-3 rounded-xl bg-accent/10 p-3 text-sm">Au tour de <strong>{{ $pool->draft->currentMember->user->name }}</strong></div>
                    @endif
                    @if ($canManage)
                        <div class="mt-4 grid gap-2">
                            @if ($pool->draft->status->value === 'pending')
                                <flux:button wire:click="startDraft" variant="primary" class="w-full">Fermer les inscriptions et démarrer</flux:button>
                            @elseif ($pool->draft->status->value === 'active')
                                <flux:button wire:click="pauseDraft" class="w-full">Mettre en pause</flux:button>
                            @elseif ($pool->draft->status->value === 'paused')
                                <flux:button wire:click="resumeDraft" variant="primary" class="w-full">Reprendre</flux:button>
                            @endif
                        </div>
                    @endif
                </flux:card>
            </div>
        </div>
    </div>
</section>
