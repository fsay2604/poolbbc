
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Mes pools</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">Créez une ligue privée ou rejoignez vos proches avec un code.</flux:text>
            </div>
            <x-action-message on="pool-created">Le pool a été créé.</x-action-message>
            <x-action-message on="pool-joined">Vous avez rejoint le pool.</x-action-message>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid gap-4 sm:grid-cols-2">
                @forelse ($pools as $pool)
                    <a href="{{ route('pools.show', $pool) }}" wire:navigate.hover class="group rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                        <flux:card class="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-lg">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <flux:heading size="lg">{{ $pool->name }}</flux:heading>
                                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $pool->season->name }}</flux:text>
                                </div>
                                <flux:badge color="zinc">{{ ucfirst($pool->status->value) }}</flux:badge>
                            </div>
                            <div class="mt-6 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <div class="text-zinc-500">Membres</div>
                                    <div class="mt-1 font-semibold tabular-nums">{{ $pool->active_members_count }} / {{ $pool->max_members }}</div>
                                </div>
                                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <div class="text-zinc-500">Repêchage</div>
                                    <div class="mt-1 font-semibold">{{ $pool->draft->status->value }}</div>
                                </div>
                            </div>
                        </flux:card>
                    </a>
                @empty
                    <flux:card class="sm:col-span-2">
                        <div class="py-10 text-center">
                            <flux:heading size="lg">Aucun pool pour le moment</flux:heading>
                            <flux:text class="mt-2">Commencez par créer votre pool ou saisir un code d’invitation.</flux:text>
                        </div>
                    </flux:card>
                @endforelse
            </div>

            <div class="flex flex-col gap-4">
                <flux:card>
                    <flux:heading size="lg">Rejoindre un pool</flux:heading>
                    <form wire:submit="join" class="mt-4 flex flex-col gap-4">
                        <flux:input wire:model="joinForm.invite_code" label="Code d’invitation" maxlength="8" autocomplete="off" />
                        <flux:button type="submit" variant="primary" class="w-full">Rejoindre</flux:button>
                    </form>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">Créer un pool</flux:heading>
                    <form wire:submit="create" class="mt-4 grid gap-4">
                        <flux:select wire:model="form.season_id" label="Saison">
                            <option value="">Choisir une saison</option>
                            @foreach ($seasons as $season)
                                <option value="{{ $season->id }}">{{ $season->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="form.name" label="Nom du pool" />
                        <flux:textarea wire:model="form.description" label="Description" rows="2" />
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="form.max_members" label="Membres max." type="number" min="1" max="50" />
                            <flux:input wire:model="form.picks_per_member" label="Choix / membre" type="number" min="1" max="20" />
                        </div>
                        <flux:select wire:model="form.draft_mode" label="Ordre du repêchage">
                            <option value="snake">Serpent</option>
                            <option value="linear">Linéaire</option>
                        </flux:select>
                        <flux:switch wire:model="form.exclusive_draft" label="Une célébrité par équipe seulement" />
                        <flux:button type="submit" variant="primary" class="w-full">Créer le pool</flux:button>
                    </form>
                </flux:card>
            </div>
        </div>
    </div>
</section>
