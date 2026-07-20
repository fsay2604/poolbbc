@php
    $canManage = $this->canManage;
    $officialRounds = $this->officialRounds;
    $rounds = $this->rounds;
@endphp

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Rondes et événements · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Gérez la structure officielle de la saison et les événements propres à ce pool.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <div class="flex flex-wrap gap-4">
            <x-action-message on="round-created">La ronde locale a été créée.</x-action-message>
            <x-action-message on="round-updated">La ronde locale a été mise à jour.</x-action-message>
            <x-action-message on="round-deleted">La ronde locale a été supprimée.</x-action-message>
            <x-action-message on="event-created">L’événement local a été créé en brouillon.</x-action-message>
            <x-action-message on="event-updated">L’événement local a été mis à jour.</x-action-message>
            <x-action-message on="event-deleted">L’événement local a été supprimé.</x-action-message>
            <x-action-message on="events-reordered">L’ordre des événements a été mis à jour.</x-action-message>
            <x-action-message on="event-cancelled">L’événement a été annulé sans attribuer de points.</x-action-message>
            <x-action-message on="pool-event-rules-updated">Les règles de cet événement officiel ont été mises à jour pour le pool.</x-action-message>
        </div>

        @can('admin')
            <div class="space-y-4">
                <livewire:admin.official-rounds :pool="$pool" :embedded="true" />
            </div>
        @endcan

        @if ($officialRounds->isNotEmpty())
            <div class="space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">Règles officielles propres à ce pool</flux:heading>
                    <flux:text class="mt-1 text-sm">La structure appartient à la saison; le mode, la visibilité et le pointage ci-dessous appartiennent uniquement à {{ $pool->name }}.</flux:text>
                </div>

                @foreach ($officialRounds as $officialRound)
                    <flux:card wire:key="official-pool-rules-round-{{ $officialRound->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <flux:heading>{{ $officialRound->name }}</flux:heading>
                            <flux:badge color="zinc">{{ $officialRound->events->count() }} événement(s)</flux:badge>
                        </div>

                        <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($officialRound->events as $officialEvent)
                                @php
                                    $officialPoolEvent = $officialEvent->poolEvents->first();
                                    $officialStatus = $officialEvent->effectiveStatus();
                                    $officialRulesEditable = $canManage
                                        && $officialPoolEvent
                                        && in_array($officialStatus->value, ['draft', 'open'], true)
                                        && $officialPoolEvent->predictions_count === 0
                                        && $officialPoolEvent->point_entries_count === 0;
                                @endphp
                                <div class="py-4" wire:key="official-pool-rules-event-{{ $officialEvent->id }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium">{{ $officialEvent->name }}</div>
                                            @if ($officialEvent->question)
                                                <div class="mt-1 text-sm text-zinc-500">{{ $officialEvent->question }}</div>
                                            @endif
                                        </div>
                                        <flux:badge :color="$officialStatus->value === 'published' ? 'green' : ($officialStatus->value === 'open' ? 'blue' : 'zinc')">{{ $officialStatus->label() }}</flux:badge>
                                    </div>

                                    @if ($canManage && $officialPoolEvent && in_array($officialPoolEvent->mode->value, ['prediction', 'hybrid'], true))
                                        <div class="mt-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div class="text-sm font-medium">Participation · réponses masquées</div>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                @foreach ($activeMembers as $activeMember)
                                                    @php
                                                        $hasResponded = in_array($activeMember->id, $officialRespondedMemberIds[$officialPoolEvent->id] ?? [], true);
                                                    @endphp
                                                    <div class="flex items-center justify-between gap-2 text-sm" wire:key="official-response-status-{{ $officialPoolEvent->id }}-{{ $activeMember->id }}">
                                                        <span class="truncate">{{ $activeMember->user->name }}</span>
                                                        <flux:badge size="sm" :color="$hasResponded ? 'green' : 'zinc'">{{ $hasResponded ? 'Répondu' : 'À répondre' }}</flux:badge>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if ($officialPoolEvent)
                                        <flux:accordion class="mt-3" transition>
                                            <flux:accordion.item>
                                                <flux:accordion.heading>Mode et pointage du pool</flux:accordion.heading>
                                                <flux:accordion.content>
                                                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                                                        <div>Mode : <strong>{{ $officialPoolEvent->mode->label() }}</strong></div>
                                                        <div>Réponses : <strong>{{ $officialPoolEvent->prediction_min_selections }} à {{ $officialPoolEvent->prediction_max_selections }}</strong></div>
                                                        <div>Équipe : <strong>{{ data_get($officialPoolEvent->scoring_config, 'owner.points_per_match', 0) }} pts / correspondance</strong></div>
                                                        <div>Prédiction : <strong>{{ data_get($officialPoolEvent->scoring_config, 'prediction.points_per_correct', 0) }} pts / bonne réponse</strong></div>
                                                    </div>

                                                    @if ($officialRulesEditable)
                                                        <form wire:submit="saveOfficialRules({{ $officialPoolEvent->id }})" class="mt-4 space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                                            <div class="grid gap-3 md:grid-cols-3">
                                                                <flux:select wire:model.live="officialRuleForms.{{ $officialPoolEvent->id }}.mode" label="Mode">
                                                                    @if ($pool->competition_mode->value !== 'prediction_only')
                                                                        <option value="roster">Équipe</option>
                                                                    @endif
                                                                    @if ($pool->competition_mode->value !== 'roster_only')
                                                                        <option value="prediction">Prédiction</option>
                                                                    @endif
                                                                    @if ($pool->competition_mode->value === 'hybrid')
                                                                        <option value="hybrid">Hybride</option>
                                                                    @endif
                                                                </flux:select>
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.prediction_min_selections" type="number" min="0" label="Réponses min." />
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.prediction_max_selections" type="number" min="1" label="Réponses max." />
                                                            </div>
                                                            <flux:select wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.visibility" label="Afficher les prédictions">
                                                                <option value="after_lock">Après le verrouillage</option>
                                                                <option value="after_publish">Après la publication du résultat</option>
                                                            </flux:select>
                                                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.owner_points" type="number" label="Points propriétaire" />
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.prediction_points" type="number" label="Points / bonne réponse" />
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.exact_bonus" type="number" label="Bonus exact" />
                                                                <flux:input wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.wrong_penalty" type="number" max="0" label="Pénalité erreur" />
                                                            </div>
                                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                                <flux:switch wire:model="officialRuleForms.{{ $officialPoolEvent->id }}.allow_negative" label="Permettre un total négatif" />
                                                                <flux:button type="submit" size="sm" variant="primary">Enregistrer les règles</flux:button>
                                                            </div>
                                                        </form>
                                                    @endif
                                                </flux:accordion.content>
                                            </flux:accordion.item>
                                        </flux:accordion>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif

        <div class="space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">Structure propre au pool</flux:heading>
                    <flux:text class="mt-1 text-sm">Ces rondes et événements existent seulement dans {{ $pool->name }}.</flux:text>
                </div>
                @if ($canManage)
                    <div class="flex flex-wrap gap-2">
                        <flux:button wire:click="startRoundCreation" icon="plus">Nouvelle ronde</flux:button>
                        <flux:button wire:click="startEventCreation" icon="plus" variant="primary" :disabled="$rounds->isEmpty()">Nouvel événement</flux:button>
                    </div>
                @endif
            </div>

            @forelse ($rounds as $round)
                @php
                    $roundDeletable = $round->events->every(fn ($event) => $event->effectiveStatus()->value === 'draft' && $event->predictions_count === 0 && $event->results_count === 0);
                @endphp
                <flux:card wire:key="local-structure-round-{{ $round->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <flux:heading size="lg">{{ $round->name }}</flux:heading>
                            <div class="mt-1 text-sm text-zinc-500">
                                {{ $round->starts_at?->timezone($pool->timezone)->translatedFormat('j M Y H:i') ?? 'Début libre' }}
                                · {{ $round->ends_at?->timezone($pool->timezone)->translatedFormat('j M Y H:i') ?? 'Fin libre' }}
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge color="zinc">{{ $round->events->count() }} événement(s)</flux:badge>
                            @if ($canManage)
                                <flux:button size="sm" wire:click="startRoundEdit({{ $round->id }})" icon="pencil-square">Modifier</flux:button>
                                @if ($roundDeletable)
                                    <flux:button size="sm" variant="danger" wire:click="startRoundDeletion({{ $round->id }})" icon="trash">Supprimer</flux:button>
                                @endif
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($round->events as $event)
                            @php
                                $status = $event->effectiveStatus();
                                $definitionEditable = $status->value === 'draft' && $event->predictions_count === 0 && $event->results_count === 0;
                            @endphp
                            <div class="py-5" wire:key="local-structure-event-{{ $event->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="font-medium">{{ $event->name }}</div>
                                            <flux:badge :color="$status->value === 'open' ? 'blue' : ($status->value === 'published' ? 'green' : 'zinc')">{{ $status->label() }}</flux:badge>
                                            <flux:badge color="zinc">{{ $event->mode->label() }}</flux:badge>
                                        </div>
                                        @if ($event->question)
                                            <div class="mt-1 text-sm text-zinc-500">{{ $event->question }}</div>
                                        @endif
                                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                                            <span>{{ $event->options->count() }} choix</span>
                                            <span>Prédiction {{ $event->prediction_min_selections }}–{{ $event->prediction_max_selections }}</span>
                                            <span>Résultat {{ $event->result_min_selections }}–{{ $event->result_max_selections }}</span>
                                            <span>Verrouillage : {{ $event->locks_at?->timezone($pool->timezone)->translatedFormat('j M Y H:i') ?? 'manuel' }}</span>
                                        </div>
                                    </div>

                                    @if ($canManage)
                                        <div class="flex flex-wrap gap-2">
                                            @if (in_array($round->id, $reorderableRoundIds, true))
                                                <flux:button size="sm" wire:click="moveEvent({{ $event->id }}, 'up')" aria-label="Monter {{ $event->name }}">↑</flux:button>
                                                <flux:button size="sm" wire:click="moveEvent({{ $event->id }}, 'down')" aria-label="Descendre {{ $event->name }}">↓</flux:button>
                                            @endif
                                            @if ($definitionEditable)
                                                <flux:button size="sm" wire:click="startEventEdit({{ $event->id }})" icon="pencil-square">Modifier</flux:button>
                                                <flux:button size="sm" variant="danger" wire:click="startEventDeletion({{ $event->id }})" icon="trash">Supprimer</flux:button>
                                            @endif
                                            @if ($status->value === 'draft')
                                                <flux:button size="sm" variant="primary" wire:click="openEvent({{ $event->id }})">Ouvrir</flux:button>
                                            @elseif ($status->value === 'open')
                                                <flux:button size="sm" variant="primary" wire:click="lockEvent({{ $event->id }})">Verrouiller</flux:button>
                                            @endif
                                            @if (in_array($status->value, ['draft', 'open', 'locked'], true))
                                                <flux:button size="sm" variant="danger" wire:click="startCancellation({{ $event->id }})">Annuler</flux:button>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                @if ($canManage && in_array($event->mode->value, ['prediction', 'hybrid'], true))
                                    <div class="mt-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                        <div class="text-sm font-medium">Participation · réponses masquées</div>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach ($activeMembers as $activeMember)
                                                @php
                                                    $hasResponded = in_array($activeMember->id, $localRespondedMemberIds[$event->id] ?? [], true);
                                                @endphp
                                                <div class="flex items-center justify-between gap-2 text-sm" wire:key="local-response-status-{{ $event->id }}-{{ $activeMember->id }}">
                                                    <span class="truncate">{{ $activeMember->user->name }}</span>
                                                    <flux:badge size="sm" :color="$hasResponded ? 'green' : 'zinc'">{{ $hasResponded ? 'Répondu' : 'À répondre' }}</flux:badge>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="py-8 text-center text-sm text-zinc-500">Cette ronde ne contient aucun événement.</div>
                        @endforelse
                    </div>
                </flux:card>
            @empty
                <flux:card class="py-12 text-center">
                    <flux:heading>Aucune ronde locale</flux:heading>
                    <flux:text class="mt-2">Créez une ronde pour ajouter des événements propres à ce pool.</flux:text>
                </flux:card>
            @endforelse
        </div>

        @if ($canManage)
            <flux:modal wire:model.self="showRoundModal" class="md:w-[32rem]">
                <form wire:submit="{{ $editingRoundId === null ? 'createRound' : 'updateRound' }}" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ $editingRoundId === null ? 'Nouvelle ronde locale' : 'Modifier la ronde locale' }}</flux:heading>
                        <flux:text class="mt-1">Cette ronde appartient uniquement à {{ $pool->name }}.</flux:text>
                    </div>
                    <flux:input wire:model="roundForm.name" label="Nom" placeholder="Semaine 1 ou Finale" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="roundForm.starts_at" label="Début" type="datetime-local" />
                        <flux:input wire:model="roundForm.ends_at" label="Fin" type="datetime-local" />
                    </div>
                    <flux:error name="roundForm" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $editingRoundId === null ? 'Créer la ronde' : 'Enregistrer' }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal wire:model.self="showEventModal" class="md:w-[48rem]">
                <form wire:submit="{{ $editingEventId === null ? 'createEvent' : 'updateEvent' }}" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ $editingEventId === null ? 'Nouvel événement local' : 'Modifier l’événement local' }}</flux:heading>
                        <flux:text class="mt-1">La définition est modifiable seulement avant l’ouverture et la première réponse.</flux:text>
                    </div>
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
                            @if ($pool->competition_mode->value !== 'prediction_only')
                                <option value="roster">Équipe</option>
                            @endif
                            @if ($pool->competition_mode->value !== 'roster_only')
                                <option value="prediction">Prédiction</option>
                            @endif
                            @if ($pool->competition_mode->value === 'hybrid')
                                <option value="hybrid">Hybride</option>
                            @endif
                        </flux:select>
                        <flux:select wire:model.live="eventForm.answer_source" label="Source des choix">
                            <option value="houseguests">Candidats de la saison</option>
                            <option value="boolean">Oui / non</option>
                            <option value="custom">Choix personnalisés</option>
                        </flux:select>
                    </div>
                    @if (($eventForm['answer_source'] ?? null) === 'custom')
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
                    <flux:select wire:model="eventForm.result_publication_mode" label="Publication du résultat">
                        <option value="immediate">Publier immédiatement après confirmation</option>
                        <option value="manual">Enregistrer, puis publier manuellement</option>
                    </flux:select>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <flux:input wire:model="eventForm.owner_points" label="Points propriétaire" type="number" />
                        <flux:input wire:model="eventForm.prediction_points" label="Points / bonne réponse" type="number" />
                        <flux:input wire:model="eventForm.exact_bonus" label="Bonus exact" type="number" />
                        <flux:input wire:model="eventForm.wrong_penalty" label="Pénalité erreur" type="number" max="0" />
                    </div>
                    <div class="flex flex-wrap gap-6">
                        <flux:switch wire:model="eventForm.allow_none" label="Permettre « aucun candidat »" />
                        @if (($eventForm['answer_source'] ?? null) === 'houseguests')
                            <flux:switch wire:model="eventForm.include_inactive_houseguests" label="Inclure les candidats inactifs" />
                        @endif
                        <flux:switch wire:model="eventForm.allow_negative" label="Permettre un total négatif" />
                        @if ($editingEventId === null && blank($eventForm['event_type_id'] ?? null))
                            <flux:switch wire:model="eventForm.save_as_template" label="Enregistrer comme modèle réutilisable" />
                        @endif
                    </div>
                    <flux:error name="eventForm" />
                    <flux:error name="eventForm.custom_options" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $editingEventId === null ? 'Créer l’événement' : 'Enregistrer' }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal wire:model.self="showRoundDeletionModal" class="md:w-[32rem]">
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">Supprimer la ronde locale</flux:heading>
                        <flux:text class="mt-1">La ronde « {{ $deletingRoundName }} » et ses événements brouillons seront supprimés.</flux:text>
                    </div>
                    <flux:error name="roundDeletion" />
                    <div class="flex justify-end gap-2">
                        <flux:button type="button" wire:click="$set('showRoundDeletionModal', false)">Annuler</flux:button>
                        <flux:button type="button" variant="danger" wire:click="deleteRound" wire:loading.attr="disabled">Supprimer</flux:button>
                    </div>
                </div>
            </flux:modal>

            <flux:modal wire:model.self="showEventDeletionModal" class="md:w-[32rem]">
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">Supprimer l’événement local</flux:heading>
                        <flux:text class="mt-1">L’événement « {{ $deletingEventName }} » et ses choix seront supprimés.</flux:text>
                    </div>
                    <flux:error name="eventDeletion" />
                    <div class="flex justify-end gap-2">
                        <flux:button type="button" wire:click="$set('showEventDeletionModal', false)">Annuler</flux:button>
                        <flux:button type="button" variant="danger" wire:click="deleteEvent" wire:loading.attr="disabled">Supprimer</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        <flux:modal wire:model.self="showCancellationModal" class="md:w-[32rem]">
            <form wire:submit="cancelEvent" class="space-y-5">
                <div>
                    <flux:heading size="lg">Annuler l’événement</flux:heading>
                    <flux:text class="mt-1">Aucun point ne sera attribué. La raison restera dans la piste d’audit.</flux:text>
                </div>
                <flux:textarea wire:model="cancellationReason" label="Raison obligatoire" rows="3" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" x-on:click="$wire.showCancellationModal = false">Retour</flux:button>
                    <flux:button type="submit" variant="danger">Confirmer l’annulation</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
