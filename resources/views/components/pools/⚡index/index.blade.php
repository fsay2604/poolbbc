
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
                                <flux:badge color="zinc">{{ $pool->status->label() }}</flux:badge>
                            </div>
                            <div class="mt-6 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <div class="text-zinc-500">Membres</div>
                                    <div class="mt-1 font-semibold tabular-nums">{{ $pool->active_members_count }} / {{ $pool->max_members }}</div>
                                </div>
                                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <div class="text-zinc-500">Mode</div>
                                    <div class="mt-1 font-semibold">{{ $pool->competition_mode->label() }}</div>
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
                    <form wire:submit="previewJoin" class="mt-4 flex flex-col gap-4">
                        <flux:input wire:model.live.debounce.500ms="joinForm.invite_code" label="Code d’invitation" maxlength="8" autocomplete="off" />
                        <flux:button type="submit" class="w-full">Consulter l’invitation</flux:button>
                    </form>

                    @if ($joinPreview)
                        <div class="mt-5 space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div>
                                <div class="font-semibold">{{ $joinPreview->name }}</div>
                                <div class="mt-1 text-sm text-zinc-500">{{ $joinPreview->season->name }}</div>
                                @if ($joinPreview->description)
                                    <div class="mt-2 text-sm">{{ $joinPreview->description }}</div>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div><span class="text-zinc-500">Membres</span><br><strong>{{ $joinPreview->active_members_count }} / {{ $joinPreview->max_members }}</strong></div>
                                <div><span class="text-zinc-500">Mode</span><br><strong>{{ $joinPreview->competition_mode->label() }}</strong></div>
                                @if ($joinPreview->usesDraft())
                                    <div><span class="text-zinc-500">Choix</span><br><strong>{{ $joinPreview->picks_per_member }} / membre</strong></div>
                                    <div><span class="text-zinc-500">Exclusivité</span><br><strong>{{ $joinPreview->exclusive_draft ? 'Oui' : 'Non' }}</strong></div>
                                @endif
                            </div>
                            <div class="border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
                                @if ($joinPreview->scoring_config === [])
                                    <div class="font-medium">Barème standard de chaque événement</div>
                                    <div class="mt-1 text-zinc-600 dark:text-zinc-300">Les réglages officiels sont hérités au moment de la création de l’événement.</div>
                                @else
                                    <div class="font-medium">Barème personnalisé des prédictions</div>
                                    <div class="mt-2 grid grid-cols-2 gap-2 text-zinc-600 dark:text-zinc-300">
                                        <span>Bonne réponse</span><strong class="text-end">{{ data_get($joinPreview->scoring_config, 'prediction.points_per_correct', 0) }} pts</strong>
                                        <span>Bonus exact</span><strong class="text-end">{{ data_get($joinPreview->scoring_config, 'prediction.exact_match_bonus', 0) }} pts</strong>
                                        <span>Pénalité</span><strong class="text-end">{{ data_get($joinPreview->scoring_config, 'prediction.wrong_answer_penalty', 0) }} pts</strong>
                                    </div>
                                @endif
                            </div>
                            <flux:button wire:click="join" variant="primary" class="w-full">Accepter et rejoindre</flux:button>
                        </div>
                    @endif
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
                        <flux:select wire:model.live="form.competition_mode" label="Mode de compétition">
                            <option value="prediction_only">Prédictions seulement</option>
                            <option value="roster_only">Équipes seulement</option>
                            <option value="hybrid">Hybride</option>
                        </flux:select>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="form.max_members" label="Membres max." type="number" min="1" max="50" />
                            <flux:input wire:model="form.picks_per_member" label="Choix / membre" type="number" min="1" max="20" />
                        </div>
                        <flux:switch wire:model.live="form.use_scoring_overrides" label="Personnaliser le barème des événements officiels" />
                        @if ($form['use_scoring_overrides'])
                        <flux:accordion transition>
                            <flux:accordion.item>
                                <flux:accordion.heading>Barème par défaut</flux:accordion.heading>
                                <flux:accordion.content>
                                    <div class="grid gap-4">
                                        <flux:text class="text-sm">Ce barème sera repris automatiquement par chaque nouvel événement officiel du pool.</flux:text>
                                        <div class="grid grid-cols-2 gap-3">
                                            <flux:input wire:model="form.scoring_config.prediction.points_per_correct" label="Bonne prédiction" type="number" />
                                            <flux:input wire:model="form.scoring_config.prediction.exact_match_bonus" label="Bonus exact" type="number" />
                                        </div>
                                        <flux:input wire:model="form.scoring_config.prediction.wrong_answer_penalty" label="Pénalité erreur" type="number" max="0" />
                                        <flux:switch wire:model="form.scoring_config.allow_negative" label="Permettre un total négatif" />
                                        <div class="grid grid-cols-2 gap-3">
                                            <flux:input wire:model="form.scoring_config.event_types.head-of-household.owner_points" label="Patron" type="number" />
                                            <flux:input wire:model="form.scoring_config.event_types.nomination.owner_points" label="Mise en danger" type="number" />
                                            <flux:input wire:model="form.scoring_config.event_types.veto-winner.owner_points" label="Veto" type="number" />
                                            <flux:input wire:model="form.scoring_config.event_types.eviction.owner_points" label="Élimination" type="number" />
                                            <flux:input wire:model="form.scoring_config.event_types.season-winner.owner_points" label="Gagnant final" type="number" class="col-span-2" />
                                        </div>
                                    </div>
                                </flux:accordion.content>
                            </flux:accordion.item>
                        </flux:accordion>
                        @else
                            <flux:text class="text-sm text-zinc-500">Chaque événement héritera du standard officiel, que vous pourrez encore adapter avant la première réponse.</flux:text>
                        @endif
                        @if ($form['competition_mode'] !== 'prediction_only')
                            <flux:select wire:model="form.draft_mode" label="Ordre du repêchage">
                                <option value="snake">Serpent</option>
                                <option value="linear">Linéaire</option>
                            </flux:select>
                            <flux:switch wire:model="form.exclusive_draft" label="Une célébrité par équipe seulement" />
                        @endif
                        <flux:button type="submit" variant="primary" class="w-full">Créer le pool</flux:button>
                    </form>
                </flux:card>
            </div>
        </div>
    </div>
</section>
