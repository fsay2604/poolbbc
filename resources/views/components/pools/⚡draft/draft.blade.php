
<section class="w-full">
    <div class="flex flex-col gap-6" wire:poll.3s="refreshDraft">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Repêchage · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">{{ $pool->draft_mode->value === 'snake' ? 'Ordre serpent' : 'Ordre linéaire' }} · {{ $pool->picks_per_member }} choix par membre</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <x-action-message on="draft-pick-made">Votre choix est confirmé et ne peut plus être modifié.</x-action-message>
        <x-action-message on="draft-pick-corrected">Le choix a été corrigé et l’action a été auditée.</x-action-message>

        @error('draft')
            <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">{{ $message }}</div>
        @enderror

        <flux:card class="sticky top-4 z-10 overflow-hidden shadow-lg lg:static lg:shadow-none">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">État : {{ $draft->status->label() }}</flux:text>
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
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-accent transition-all" style="width: {{ $totalPicks > 0 ? ($completedPicks / $totalPicks) * 100 : 0 }}%"></div>
            </div>
            <flux:text class="mt-2 text-xs text-zinc-500">{{ $completedPicks }} choix confirmés sur {{ $totalPicks }}</flux:text>
        </flux:card>

        @if ($canPick)
            <div>
                <flux:heading size="lg">Votre sélection</flux:heading>
                <flux:text class="mt-1 text-sm">Choisissez une célébrité disponible. La sélection est définitive.</flux:text>
                <div class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher une célébrité" clearable />
                    <flux:select wire:model.live="sexFilter" placeholder="Tous les profils">
                        <flux:select.option value="">Tous les profils</flux:select.option>
                        <flux:select.option value="F">Femmes</flux:select.option>
                        <flux:select.option value="M">Hommes</flux:select.option>
                    </flux:select>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-7">
                    @foreach ($availableHouseguests as $houseguest)
                        <flux:card class="flex flex-col gap-3 p-3" wire:key="available-{{ $houseguest->id }}">
                            <div class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                @if ($houseguest->avatar_url)
                                    <img src="{{ $houseguest->avatar_url }}" alt="{{ $houseguest->name }}" class="size-full object-cover" />
                                @endif
                            </div>
                            <div class="min-w-0 truncate text-sm font-medium">{{ $houseguest->name }}</div>
                            <flux:button size="sm" variant="primary" wire:click="confirmSelection({{ $houseguest->id }})" class="w-full">Choisir</flux:button>
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
                                <div class="flex items-center gap-1">
                                    <flux:badge color="blue">{{ $pick->houseguest->name }}</flux:badge>
                                    @if ($canCorrect)
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="startCorrection({{ $pick->id }})" aria-label="Corriger le choix de {{ $pick->houseguest->name }}" />
                                    @endif
                                </div>
                            @empty
                                <flux:text class="text-sm text-zinc-500">Aucun choix encore.</flux:text>
                            @endforelse
                        </div>
                    </flux:card>
                @endforeach
            </div>
        </div>

        <flux:modal wire:model.self="showConfirmPickModal" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Confirmer ce choix définitif?</flux:heading>
                    <flux:text class="mt-2">Vous allez sélectionner <strong>{{ $confirmingHouseguestName }}</strong>. Le choix ne pourra être corrigé que par un administrateur.</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button x-on:click="$wire.showConfirmPickModal = false">Annuler</flux:button>
                    <flux:button wire:click="pick" wire:loading.attr="disabled" wire:target="pick" variant="primary">Confirmer le choix</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal wire:model.self="showCorrectionModal" class="md:w-[32rem]">
            <form wire:submit="correct" class="space-y-6">
                <div>
                    <flux:heading size="lg">Corriger un choix de repêchage</flux:heading>
                    <flux:text class="mt-2">Cette modification est exceptionnelle et sera inscrite au journal d’audit.</flux:text>
                </div>
                <flux:select wire:model="correctionHouseguestId" label="Célébrité de remplacement">
                    <flux:select.option value="">Choisir un remplacement</flux:select.option>
                    @foreach ($correctionHouseguests as $houseguest)
                        <flux:select.option :value="$houseguest->id">{{ $houseguest->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="correctionHouseguestId" />
                <flux:textarea wire:model="correctionReason" label="Justification" rows="3" />
                <flux:error name="correctionReason" />
                <div class="flex justify-end gap-2">
                    <flux:button x-on:click="$wire.showCorrectionModal = false">Annuler</flux:button>
                    <flux:button type="submit" variant="primary">Enregistrer la correction</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
