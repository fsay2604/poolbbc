
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Rondes et événements · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Échéances affichées dans le fuseau {{ $pool->timezone }}.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <div class="flex flex-wrap gap-4">
            <x-action-message on="round-created">La ronde a été créée.</x-action-message>
            <x-action-message on="event-created">L’événement a été créé en brouillon.</x-action-message>
            <x-action-message on="prediction-saved">Votre prédiction a été enregistrée.</x-action-message>
            <x-action-message on="result-published">Le résultat et les points ont été publiés.</x-action-message>
        </div>

        @if ($canManage)
            <div class="grid gap-6 xl:grid-cols-[20rem_minmax(0,1fr)]">
                <flux:card>
                    <flux:heading size="lg">Nouvelle ronde</flux:heading>
                    <form wire:submit="createRound" class="mt-4 grid gap-4">
                        <flux:input wire:model="roundForm.name" label="Nom" placeholder="Semaine 1 ou Finale" />
                        <flux:input wire:model="roundForm.starts_at" label="Début" type="datetime-local" />
                        <flux:input wire:model="roundForm.ends_at" label="Fin" type="datetime-local" />
                        <flux:button type="submit" variant="primary" class="w-full">Créer la ronde</flux:button>
                    </form>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">Nouvel événement</flux:heading>
                    @if ($rounds->isEmpty())
                        <flux:callout class="mt-4" icon="information-circle">Créez d’abord une ronde.</flux:callout>
                    @else
                        <form wire:submit="createEvent" class="mt-4 grid gap-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:select wire:model="eventForm.round_id" label="Ronde">
                                    <option value="">Choisir</option>
                                    @foreach ($rounds as $round)
                                        <option value="{{ $round->id }}">{{ $round->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model.live="eventForm.event_type_id" label="Modèle">
                                    <option value="">Personnalisé</option>
                                    @foreach ($eventTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:input wire:model="eventForm.name" label="Nom" />
                            <flux:textarea wire:model="eventForm.question" label="Question présentée aux membres" rows="2" />
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:select wire:model="eventForm.mode" label="Mode de pointage">
                                    <option value="roster">Équipe</option>
                                    <option value="prediction">Prédiction</option>
                                    <option value="hybrid">Hybride</option>
                                </flux:select>
                                <flux:select wire:model.live="eventForm.answer_source" label="Source des choix">
                                    <option value="houseguests">Célébrités actives</option>
                                    <option value="boolean">Oui / non</option>
                                    <option value="custom">Choix personnalisés</option>
                                </flux:select>
                            </div>
                            @if ($eventForm['answer_source'] === 'custom')
                                <flux:textarea wire:model="customOptionsText" label="Choix personnalisés, un par ligne" rows="4" />
                            @endif
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:input wire:model="eventForm.opens_at" label="Ouverture" type="datetime-local" />
                                <flux:input wire:model="eventForm.locks_at" label="Verrouillage" type="datetime-local" />
                            </div>
                            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                                <flux:input wire:model="eventForm.prediction_min_selections" label="Préd. min." type="number" min="0" />
                                <flux:input wire:model="eventForm.prediction_max_selections" label="Préd. max." type="number" min="1" />
                                <flux:input wire:model="eventForm.result_min_selections" label="Résultat min." type="number" min="0" />
                                <flux:input wire:model="eventForm.result_max_selections" label="Résultat max." type="number" min="1" />
                            </div>
                            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                                <flux:input wire:model="eventForm.owner_points" label="Points propriétaire" type="number" />
                                <flux:input wire:model="eventForm.prediction_points" label="Points / bonne réponse" type="number" />
                                <flux:input wire:model="eventForm.exact_bonus" label="Bonus exact" type="number" />
                                <flux:input wire:model="eventForm.wrong_penalty" label="Pénalité erreur" type="number" max="0" />
                            </div>
                            <div class="flex flex-wrap gap-6">
                                <flux:switch wire:model="eventForm.allow_none" label="Permettre « aucune célébrité »" />
                                <flux:switch wire:model="eventForm.allow_negative" label="Permettre un total négatif" />
                            </div>
                            <flux:button type="submit" variant="primary">Créer l’événement</flux:button>
                        </form>
                    @endif
                </flux:card>
            </div>
        @endif

        @forelse ($rounds as $round)
            <div class="flex flex-col gap-4" wire:key="round-{{ $round->id }}">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ $round->name }}</flux:heading>
                    <flux:badge color="zinc">{{ $round->events->count() }} événement(s)</flux:badge>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    @foreach ($round->events as $event)
                        <flux:card wire:key="event-{{ $event->id }}">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <flux:heading>{{ $event->name }}</flux:heading>
                                    @if ($event->question)
                                        <flux:text class="mt-1">{{ $event->question }}</flux:text>
                                    @endif
                                </div>
                                <flux:badge :color="$event->status->value === 'published' ? 'green' : ($event->status->value === 'open' ? 'blue' : 'zinc')">{{ $event->status->value }}</flux:badge>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2 text-xs text-zinc-500">
                                <span>{{ $event->mode->value }}</span><span>·</span>
                                <span>{{ $event->predictions_count }} réponse(s)</span>
                                @if ($event->locks_at)
                                    <span>·</span><span>fermeture {{ $event->locks_at->copy()->setTimezone($pool->timezone)->translatedFormat('j M Y H:i') }}</span>
                                @endif
                            </div>

                            @if ($event->status->value === 'open' && in_array($event->mode->value, ['prediction', 'hybrid'], true))
                                <flux:checkbox.group wire:model="predictionSelections.{{ $event->id }}" :label="__('Votre prédiction')" class="mt-5">
                                    @foreach ($event->options as $option)
                                        <flux:checkbox :value="$option->id" :label="$option->label" wire:key="prediction-option-{{ $event->id }}-{{ $option->id }}" />
                                    @endforeach
                                </flux:checkbox.group>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <flux:button size="sm" wire:click="submitPrediction({{ $event->id }}, false)">Enregistrer le brouillon</flux:button>
                                    <flux:button size="sm" variant="primary" wire:click="submitPrediction({{ $event->id }}, true)">Soumettre</flux:button>
                                </div>
                            @endif

                            @if (in_array($event->status->value, ['locked', 'published'], true))
                                <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                                    <flux:subheading>Prédictions révélées</flux:subheading>
                                    <div class="mt-3 space-y-2">
                                        @forelse ($event->predictions as $prediction)
                                            <div class="flex items-start justify-between gap-3 text-sm">
                                                <span class="font-medium">{{ $prediction->poolMember->user->name }}</span>
                                                <span class="text-end text-zinc-500">{{ $prediction->options->pluck('label')->implode(', ') }}</span>
                                            </div>
                                        @empty
                                            <flux:text class="text-sm text-zinc-500">Aucune prédiction soumise.</flux:text>
                                        @endforelse
                                    </div>
                                </div>
                            @endif

                            @if ($canManage)
                                <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                                    @if ($event->status->value === 'draft')
                                        <flux:button size="sm" variant="primary" wire:click="openEvent({{ $event->id }})">Ouvrir aux prédictions</flux:button>
                                    @elseif ($event->status->value === 'open')
                                        <flux:button size="sm" wire:click="lockEvent({{ $event->id }})">Verrouiller maintenant</flux:button>
                                    @elseif (in_array($event->status->value, ['locked', 'published'], true))
                                        <flux:checkbox.group wire:model="resultSelections.{{ $event->id }}" :label="__('Résultat officiel')">
                                            @foreach ($event->options as $option)
                                                <flux:checkbox :value="$option->id" :label="$option->label" wire:key="result-option-{{ $event->id }}-{{ $option->id }}" />
                                            @endforeach
                                        </flux:checkbox.group>
                                        @if ($event->status->value === 'published')
                                            <flux:textarea wire:model="correctionReasons.{{ $event->id }}" label="Justification obligatoire de la correction" class="mt-3" rows="2" />
                                        @endif
                                        <div class="mt-4 flex flex-wrap gap-2">
                                            <flux:button size="sm" wire:click="previewResult({{ $event->id }})">Aperçu des points</flux:button>
                                            <flux:button size="sm" variant="primary" wire:click="publishResult({{ $event->id }})">{{ $event->status->value === 'published' ? 'Publier la correction' : 'Publier le résultat' }}</flux:button>
                                        </div>
                                        @if (! empty($scorePreviews[$event->id] ?? []))
                                            <div class="mt-4 rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                                @foreach ($scorePreviews[$event->id] as $preview)
                                                    <div class="flex justify-between gap-3 py-1"><span>{{ $preview['name'] }}</span><strong>{{ $preview['points'] }} pts</strong></div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:card><div class="py-10 text-center text-sm text-zinc-500">Aucune ronde pour ce pool.</div></flux:card>
        @endforelse
    </div>
</section>
