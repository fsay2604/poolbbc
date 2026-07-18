
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl" level="1">{{ $pool->name }}</flux:heading>
                    <flux:badge>{{ $pool->status->label() }}</flux:badge>
                </div>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $pool->season->name }} · {{ $pool->timezone }}</flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($pool->usesDraft())
                    <flux:button :href="route('pools.draft', $pool)" icon="queue-list" wire:navigate.hover>Repêchage</flux:button>
                @endif
                <flux:button :href="route('pools.events', $pool)" icon="calendar-days" wire:navigate.hover>Rondes et événements</flux:button>
                <flux:button :href="route('pools.leaderboard', $pool)" icon="trophy" variant="primary" wire:navigate.hover>Classement</flux:button>
            </div>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <x-action-message on="draft-updated">Le repêchage a été mis à jour.</x-action-message>
        <x-action-message on="draft-order-updated">L’ordre du repêchage a été confirmé.</x-action-message>
        <x-action-message on="pool-activated">Le pool est maintenant actif.</x-action-message>
        <x-action-message on="pool-registrations-opened">Les inscriptions sont maintenant ouvertes.</x-action-message>
        <x-action-message on="pool-completed">Le pool est maintenant terminé et en lecture seule.</x-action-message>
        <x-action-message on="pool-archived">Le pool a été archivé.</x-action-message>
        <x-action-message on="member-removed">Le membre a été retiré du pool.</x-action-message>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card><flux:text class="text-sm text-zinc-500">Membres</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->members->count() }} / {{ $pool->max_members }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Mode</flux:text><flux:heading size="lg" class="mt-2">{{ $pool->competition_mode->label() }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Rondes</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->rounds_count }}</flux:heading></flux:card>
            <flux:card><flux:text class="text-sm text-zinc-500">Événements</flux:text><flux:heading size="xl" class="mt-2 tabular-nums">{{ $pool->events_count }}</flux:heading></flux:card>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text class="text-sm text-zinc-500">Votre classement</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">{{ $summary['rank'] ? '#'.$summary['rank'] : '—' }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ $summary['total_points'] }} points</flux:text>
            </flux:card>
            <flux:card>
                <flux:text class="text-sm text-zinc-500">Progression des prédictions</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">{{ $summary['predictions_submitted'] }} / {{ $summary['predictions_total'] }}</flux:heading>
                @if ($pool->usesPredictions() && ! in_array($pool->status->value, ['completed', 'archived'], true))
                    <flux:link :href="route('pools.predictions', $pool)" wire:navigate.hover class="mt-1 text-sm">Continuer</flux:link>
                @endif
            </flux:card>
            <flux:card>
                <flux:text class="text-sm text-zinc-500">Prochaine échéance</flux:text>
                @if (in_array($pool->status->value, ['completed', 'archived'], true))
                    <flux:heading size="lg" class="mt-2">—</flux:heading>
                    <flux:text class="mt-1 truncate text-sm">Historique en lecture seule</flux:text>
                @else
                    <flux:heading size="lg" class="mt-2">{{ $summary['next_deadline'] ?? 'À venir' }}</flux:heading>
                    <flux:text class="mt-1 truncate text-sm">{{ $summary['next_event'] ?? 'Aucun événement ouvert' }}</flux:text>
                @endif
            </flux:card>
            <flux:card>
                <flux:text class="text-sm text-zinc-500">Points récents</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">{{ $recentPoints->sum('points') > 0 ? '+' : '' }}{{ $recentPoints->sum('points') }}</flux:heading>
                <flux:text class="mt-1 text-sm">Sur les {{ $recentPoints->count() }} dernières écritures</flux:text>
            </flux:card>
        </div>

        @if ($recentPoints->isNotEmpty())
            <flux:card>
                <div class="flex items-center justify-between gap-3">
                    <flux:heading>Activité récente</flux:heading>
                    <flux:link :href="route('pools.leaderboard', $pool)" wire:navigate.hover>Voir le détail</flux:link>
                </div>
                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($recentPoints as $entry)
                        <div class="flex items-start justify-between gap-4 py-3" wire:key="recent-point-{{ $entry->id }}">
                            <div>
                                <div class="font-medium">{{ $entry->event?->name ?? $entry->poolEvent?->seasonEvent?->name ?? 'Ajustement' }}</div>
                                <div class="text-sm text-zinc-500">{{ $entry->reason }}</div>
                            </div>
                            <div class="font-semibold tabular-nums {{ $entry->points < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $entry->points > 0 ? '+' : '' }}{{ $entry->points }}</div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <flux:card>
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">Membres {{ $pool->usesDraft() ? 'et ordre du repêchage' : '' }}</flux:heading>
                    @if ($pool->usesDraft())
                        <flux:badge color="zinc">{{ $pool->draft_mode->value === 'snake' ? 'Serpent' : 'Linéaire' }}</flux:badge>
                    @endif
                </div>
                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach ($pool->members as $member)
                        <div class="flex items-center gap-3 py-3" wire:key="member-{{ $member->id }}">
                            @if ($pool->usesDraft())
                                <div class="flex size-9 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent">{{ $member->draft_position }}</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $member->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $member->role->label() }}</div>
                            </div>
                            @if ($member->user_id === auth()->id())
                                <flux:badge color="blue">Vous</flux:badge>
                            @endif
                            @if ($canManage && $pool->usesDraft() && $pool->status->value === 'registration' && $pool->draft->status->value === 'pending')
                                <div class="flex gap-1" aria-label="Modifier la position de {{ $member->user->name }}">
                                    <flux:button size="xs" wire:click="moveDraftMember({{ $member->id }}, -1)" aria-label="Monter {{ $member->user->name }}">↑</flux:button>
                                    <flux:button size="xs" wire:click="moveDraftMember({{ $member->id }}, 1)" aria-label="Descendre {{ $member->user->name }}">↓</flux:button>
                                </div>
                            @endif
                            @if (
                                ! $pool->isReadOnly()
                                && $member->role->value !== 'owner'
                                && ($canManage || $member->user_id === auth()->id())
                                && (! $pool->usesDraft() || ! in_array($pool->draft->status->value, ['active', 'paused'], true))
                            )
                                <flux:button size="sm" variant="danger" wire:click="startMemberRemoval({{ $member->id }})">
                                    {{ $member->user_id === auth()->id() ? 'Quitter' : 'Retirer' }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>

            <div class="flex flex-col gap-4">
                @if (! in_array($pool->status->value, ['completed', 'archived'], true))
                    <flux:card>
                        <flux:heading size="lg">Invitation</flux:heading>
                        <flux:text class="mt-2 text-sm">Partagez ce code avant la fermeture des inscriptions.</flux:text>
                        <div class="mt-4 rounded-xl bg-zinc-100 px-4 py-3 text-center font-mono text-2xl font-bold tracking-[0.25em] dark:bg-zinc-800">{{ $pool->invite_code }}</div>
                    </flux:card>
                @endif
                @if ($canManageLifecycle && in_array($pool->status->value, ['active', 'completed'], true))
                    <flux:card>
                        <flux:heading size="lg">Cycle de vie du pool</flux:heading>
                        @if ($pool->status->value === 'active')
                            <flux:text class="mt-2 text-sm">Terminez le pool une fois tous les événements publiés ou annulés.</flux:text>
                            <flux:button
                                wire:click="completePool"
                                wire:confirm="Terminer ce pool? Les prédictions et les modifications seront ensuite bloquées."
                                variant="danger"
                                class="mt-4 w-full"
                            >Terminer le pool</flux:button>
                        @else
                            <flux:text class="mt-2 text-sm">L’archivage conserve le classement et l’historique en lecture seule.</flux:text>
                            <flux:button
                                wire:click="archivePool"
                                wire:confirm="Archiver définitivement ce pool?"
                                class="mt-4 w-full"
                            >Archiver le pool</flux:button>
                        @endif
                    </flux:card>
                @endif
                @if ($pool->usesDraft())
                <flux:card>
                    <flux:heading size="lg">Repêchage</flux:heading>
                    <flux:text class="mt-2 text-sm">État actuel : {{ $pool->draft->status->label() }}</flux:text>
                    @if ($pool->draft->currentMember)
                        <div class="mt-3 rounded-xl bg-accent/10 p-3 text-sm">Au tour de <strong>{{ $pool->draft->currentMember->user->name }}</strong></div>
                    @endif
                    @if ($canManage)
                        <div class="mt-4 grid gap-2">
                            @if ($pool->status->value === 'configuration')
                                <flux:button wire:click="openRegistrations" variant="primary" class="w-full">Confirmer les règles et ouvrir les inscriptions</flux:button>
                            @elseif ($pool->draft->status->value === 'pending')
                                <flux:button wire:click="randomizeDraftOrder" class="w-full">Générer un ordre aléatoire</flux:button>
                                <flux:button wire:click="confirmStartDraft" variant="primary" class="w-full">Confirmer l’ordre et démarrer</flux:button>
                            @elseif ($pool->draft->status->value === 'active')
                                <flux:button wire:click="pauseDraft" class="w-full">Mettre en pause</flux:button>
                            @elseif ($pool->draft->status->value === 'paused')
                                <flux:button wire:click="resumeDraft" variant="primary" class="w-full">Reprendre</flux:button>
                            @endif
                        </div>
                    @endif
                </flux:card>
                @elseif ($canManage && in_array($pool->status->value, ['configuration', 'registration'], true))
                    <flux:card>
                        <flux:heading size="lg">Activation</flux:heading>
                        @if ($pool->status->value === 'configuration')
                            <flux:text class="mt-2 text-sm">Confirmez les règles avant de partager le code d’invitation.</flux:text>
                            <flux:button wire:click="openRegistrations" variant="primary" class="mt-4 w-full">Confirmer et ouvrir les inscriptions</flux:button>
                        @else
                            <flux:text class="mt-2 text-sm">Fermez les inscriptions et ouvrez les prédictions.</flux:text>
                            <flux:button wire:click="activate" variant="primary" class="mt-4 w-full">Activer le pool</flux:button>
                        @endif
                    </flux:card>
                @endif
            </div>
        </div>

        <flux:modal wire:model.self="showStartDraftModal" class="md:w-[32rem]">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Confirmer l’ordre du repêchage</flux:heading>
                    <flux:text class="mt-2">Les inscriptions seront fermées et cet ordre deviendra définitif.</flux:text>
                </div>
                <ol class="space-y-2">
                    @foreach ($pool->members as $member)
                        <li class="flex items-center gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800" wire:key="draft-confirm-member-{{ $member->id }}">
                            <span class="flex size-7 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent">{{ $member->draft_position }}</span>
                            <span class="font-medium">{{ $member->user->name }}</span>
                        </li>
                    @endforeach
                </ol>
                <div class="flex justify-end gap-2">
                    <flux:button type="button" x-on:click="$wire.showStartDraftModal = false">Retour</flux:button>
                    <flux:button type="button" wire:click="startDraft" variant="primary">Fermer et démarrer</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal wire:model.self="showRemoveMemberModal" class="md:w-[32rem]">
            <form wire:submit="removeMember" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $isLeavingPool ? 'Quitter le pool' : 'Retirer ce membre' }}</flux:heading>
                    <flux:text class="mt-2">
                        L’accès au pool sera retiré. Les équipes, prédictions soumises et points déjà acquis resteront dans l’historique et le classement.
                    </flux:text>
                </div>
                <flux:textarea wire:model="memberRemovalReason" label="Raison obligatoire" rows="3" maxlength="1000" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelMemberRemoval">Retour</flux:button>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="removeMember">
                        {{ $isLeavingPool ? 'Confirmer mon départ' : 'Confirmer le retrait' }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
