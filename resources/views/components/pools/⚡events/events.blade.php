@php
    $canManage = $this->canManage;
    $officialRounds = $this->officialRounds;
    $rounds = $this->rounds;
@endphp

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Événements du pool · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Rondes, prédictions et résultats propres à ce pool · fuseau {{ $pool->timezone }}.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <div class="flex flex-wrap gap-4">
            <x-action-message on="round-created">La ronde a été créée.</x-action-message>
            <x-action-message on="event-created">L’événement a été créé en brouillon.</x-action-message>
            <x-action-message on="events-reordered">L’ordre des événements a été mis à jour.</x-action-message>
            <x-action-message on="prediction-saved">Votre prédiction a été enregistrée.</x-action-message>
            <x-action-message on="result-recorded">Le résultat est enregistré et attend une publication explicite.</x-action-message>
            <x-action-message on="result-amended">Le brouillon du résultat a été modifié.</x-action-message>
            <x-action-message on="result-published">Le résultat et les points ont été publiés.</x-action-message>
            <x-action-message on="event-cancelled">L’événement a été annulé sans attribuer de points.</x-action-message>
            <x-action-message on="pool-event-rules-updated">Le barème du pool a été mis à jour.</x-action-message>
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div>
                    <div class="font-medium">Structure locale</div>
                    <div class="text-sm text-zinc-500">Ajoutez seulement les rondes et événements propres à {{ $pool->name }}.</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button wire:click="$set('showRoundModal', true)" icon="plus">Nouvelle ronde</flux:button>
                    <flux:button wire:click="$set('showEventModal', true)" icon="plus" variant="primary" :disabled="$rounds->isEmpty()">Nouvel événement</flux:button>
                </div>
            </div>
        @endif

        @if ($officialRounds->isNotEmpty())
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Réglages des événements officiels</flux:heading>
                    <flux:text class="mt-1 text-sm">Suivez la participation et adaptez le barème de ce pool avant son gel.</flux:text>
                </div>
                @foreach ($officialRounds as $officialRound)
                    <flux:card wire:key="official-result-round-{{ $officialRound->id }}">
                        <div class="flex items-center justify-between gap-3">
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
                                <div class="py-4" wire:key="official-result-event-{{ $officialEvent->id }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium">{{ $officialEvent->name }}</div>
                                            <div class="mt-1 text-sm text-zinc-500">{{ $officialEvent->question }}</div>
                                        </div>
                                        <flux:badge :color="$officialStatus->value === 'published' ? 'green' : ($officialStatus->value === 'open' ? 'blue' : 'zinc')">
                                            {{ $officialStatus->label() }}
                                        </flux:badge>
                                    </div>
                                    @if ($canManage && $officialPoolEvent && in_array($officialStatus->value, ['draft', 'open'], true) && in_array($officialPoolEvent->mode->value, ['prediction', 'hybrid'], true))
                                        <div class="mt-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div class="text-sm font-medium">Participation · réponses cachées</div>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                @foreach ($activeMembers as $activeMember)
                                                    @php($hasResponded = in_array($activeMember->id, $officialRespondedMemberIds[$officialPoolEvent->id] ?? [], true))
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
                                                <flux:accordion.heading>Barème du pool</flux:accordion.heading>
                                                <flux:accordion.content>
                                                    <div class="space-y-3 text-sm">
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <div>Mode : <strong>{{ $officialPoolEvent->mode->label() }}</strong></div>
                                                            <div>Réponses : <strong>{{ $officialPoolEvent->prediction_min_selections }} à {{ $officialPoolEvent->prediction_max_selections }}</strong></div>
                                                            <div>Équipe : <strong>{{ data_get($officialPoolEvent->scoring_config, 'owner.points_per_match', 0) }} pts / correspondance</strong></div>
                                                            <div>Prédiction : <strong>{{ data_get($officialPoolEvent->scoring_config, 'prediction.points_per_correct', 0) }} pts / bonne réponse</strong></div>
                                                        </div>
                                                        @if ($officialRulesEditable)
                                                            <form wire:submit="saveOfficialRules({{ $officialPoolEvent->id }})" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                                                <div>
                                                                    <div class="font-medium">Adapter le barème de ce pool</div>
                                                                    <div class="mt-1 text-xs text-zinc-500">Ces paramètres seront gelés dès la première réponse ou le verrouillage.</div>
                                                                </div>
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
                                                                    <flux:button type="submit" size="sm" variant="primary">Enregistrer le barème</flux:button>
                                                                </div>
                                                            </form>
                                                        @endif
                                                    </div>
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
                                <div class="flex shrink-0 items-center gap-2">
                                    @if (in_array($round->id, $reorderableRoundIds, true))
                                        <div class="flex items-center gap-1" role="group" aria-label="Modifier la position de {{ $event->name }}">
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-up"
                                                wire:click="moveEvent({{ $event->id }}, 'up')"
                                                :disabled="$loop->first"
                                                aria-label="Monter {{ $event->name }}"
                                            />
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-down"
                                                wire:click="moveEvent({{ $event->id }}, 'down')"
                                                :disabled="$loop->last"
                                                aria-label="Descendre {{ $event->name }}"
                                            />
                                        </div>
                                    @endif
                                    <flux:badge :color="match ($event->effectiveStatus()->value) { 'published' => 'green', 'cancelled' => 'red', 'locked', 'result_entered' => 'amber', 'open' => 'blue', default => 'zinc' }">
                                        {{ $event->effectiveStatus()->label() }}
                                    </flux:badge>
                                </div>
                            </div>

                            <flux:accordion class="mt-4" transition>
                                <flux:accordion.item>
                                    <flux:accordion.heading>Règles de pointage et résultats précédents</flux:accordion.heading>
                                    <flux:accordion.content>
                                        <div class="space-y-3 text-sm">
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <div>Mode : <strong>{{ $event->mode->label() }}</strong></div>
                                                <div>Prédictions : <strong>{{ $event->prediction_min_selections }} à {{ $event->prediction_max_selections }} choix</strong></div>
                                                <div>Équipe : <strong>{{ data_get($event->scoring_config, 'owner.points_per_match', 0) }} pts / correspondance</strong></div>
                                                <div>Bonne réponse : <strong>{{ data_get($event->scoring_config, 'prediction.points_per_correct', 0) }} pts</strong></div>
                                                <div>Bonus exact : <strong>{{ data_get($event->scoring_config, 'prediction.exact_match_bonus', 0) }} pts</strong></div>
                                                <div>Pénalité : <strong>{{ data_get($event->scoring_config, 'prediction.wrong_answer_penalty', 0) }} pts</strong></div>
                                                <div>Publication : <strong>{{ $event->result_publication_mode->label() }}</strong></div>
                                            </div>
                                            @foreach ($event->results as $result)
                                                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="local-result-history-{{ $result->id }}">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <span class="font-medium">Version {{ $result->version }} · {{ $result->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</span>
                                                        <flux:badge size="sm" :color="$result->status === 'published' ? 'green' : 'amber'">
                                                            {{ $result->status === 'published' ? 'Publié' : 'À publier' }}
                                                        </flux:badge>
                                                    </div>
                                                    <div class="mt-1 text-xs text-zinc-500">
                                                        {{ $result->createdBy?->name }} · {{ ($result->published_at ?? $result->created_at)->translatedFormat('j M Y H:i') }}
                                                    </div>
                                                    @if ($result->correction_reason)
                                                        <div class="mt-2 text-zinc-600 dark:text-zinc-300">Justification : {{ $result->correction_reason }}</div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </flux:accordion.content>
                                </flux:accordion.item>
                            </flux:accordion>

                            <div class="mt-4 flex flex-wrap gap-2 text-xs text-zinc-500">
                                <span>{{ $event->mode->label() }}</span><span>·</span>
                                <span>{{ $event->predictions_count }} réponse(s)</span>
                                @if ($event->locks_at)
                                    <span>·</span><span>fermeture {{ $event->locks_at->copy()->setTimezone($pool->timezone)->translatedFormat('j M Y H:i') }}</span>
                                @endif
                            </div>

                            @if ($canManage && in_array($event->effectiveStatus()->value, ['draft', 'open'], true) && in_array($event->mode->value, ['prediction', 'hybrid'], true))
                                <div class="mt-4 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                    <div class="text-sm font-medium">Participation · réponses cachées</div>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach ($activeMembers as $activeMember)
                                            @php($hasResponded = in_array($activeMember->id, $localRespondedMemberIds[$event->id] ?? [], true))
                                            <div class="flex items-center justify-between gap-2 text-sm" wire:key="local-response-status-{{ $event->id }}-{{ $activeMember->id }}">
                                                <span class="truncate">{{ $activeMember->user->name }}</span>
                                                <flux:badge size="sm" :color="$hasResponded ? 'green' : 'zinc'">{{ $hasResponded ? 'Répondu' : 'À répondre' }}</flux:badge>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($event->effectiveStatus()->value === 'open' && in_array($event->mode->value, ['prediction', 'hybrid'], true))
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

                            @if (in_array($event->effectiveStatus()->value, ['locked', 'result_entered', 'published'], true))
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
                                    @if ($event->effectiveStatus()->value === 'draft')
                                        <flux:button size="sm" variant="primary" wire:click="openEvent({{ $event->id }})">Ouvrir aux prédictions</flux:button>
                                    @elseif ($event->effectiveStatus()->value === 'open')
                                        <flux:button size="sm" wire:click="lockEvent({{ $event->id }})">Verrouiller maintenant</flux:button>
                                    @elseif ($event->draftResult)
                                        <flux:callout icon="clock" color="amber">
                                            <flux:callout.heading>Résultat enregistré, non publié</flux:callout.heading>
                                            <flux:callout.text>{{ $event->draftResult->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</flux:callout.text>
                                        </flux:callout>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <flux:button size="sm" icon="pencil-square" wire:click="startAmendingRecordedResult({{ $event->id }})">Modifier</flux:button>
                                            <flux:button size="sm" variant="primary" wire:click="publishRecordedResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="publishRecordedResult({{ $event->id }})">
                                                Publier le résultat et les points
                                            </flux:button>
                                        </div>
                                        @if (in_array($event->id, $editingDraftResultIds, true))
                                            <div class="mt-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                                <flux:checkbox.group wire:model="resultSelections.{{ $event->id }}" :label="__('Résultat officiel')">
                                                    @foreach ($event->options as $option)
                                                        <flux:checkbox :value="$option->id" :label="$option->label" wire:key="draft-result-option-{{ $event->id }}-{{ $option->id }}" />
                                                    @endforeach
                                                </flux:checkbox.group>
                                                <flux:error :name="'resultSelections.'.$event->id" />
                                                @if ($event->latestResult)
                                                    <flux:textarea wire:model="correctionReasons.{{ $event->id }}" label="Justification obligatoire de la correction" class="mt-3" rows="2" />
                                                @endif
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <flux:button size="sm" wire:click="previewResult({{ $event->id }})">Aperçu des points</flux:button>
                                                    @if (isset($resultPreviewFingerprints[$event->id]))
                                                        <flux:button size="sm" variant="primary" wire:click="amendRecordedResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="amendRecordedResult({{ $event->id }})">Enregistrer les modifications</flux:button>
                                                    @endif
                                                </div>
                                                @if (! empty($scorePreviews[$event->id] ?? []))
                                                    <div class="mt-4 rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                                        @foreach ($scorePreviews[$event->id] as $preview)
                                                            <div class="flex justify-between gap-3 py-1"><span>{{ $preview['name'] }}</span><strong>{{ $preview['points'] }} pts</strong></div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @elseif (in_array($event->effectiveStatus()->value, ['locked', 'published'], true))
                                        <flux:checkbox.group wire:model="resultSelections.{{ $event->id }}" :label="__('Résultat officiel')">
                                            @foreach ($event->options as $option)
                                                <flux:checkbox :value="$option->id" :label="$option->label" wire:key="result-option-{{ $event->id }}-{{ $option->id }}" />
                                            @endforeach
                                        </flux:checkbox.group>
                                        @if ($event->latestResult)
                                            <flux:textarea wire:model="correctionReasons.{{ $event->id }}" label="Justification obligatoire de la correction" class="mt-3" rows="2" />
                                        @endif
                                        <div class="mt-4 flex flex-wrap gap-2">
                                            <flux:button size="sm" wire:click="previewResult({{ $event->id }})">Aperçu des points</flux:button>
                                            @if (isset($resultPreviewFingerprints[$event->id]))
                                                <flux:button size="sm" variant="primary" wire:click="publishResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="publishResult({{ $event->id }})">
                                                    @if ($event->result_publication_mode->value === 'manual')
                                                        {{ $event->latestResult ? 'Enregistrer la correction' : 'Enregistrer le résultat' }}
                                                    @else
                                                        {{ $event->latestResult ? 'Publier la correction' : 'Publier le résultat' }}
                                                    @endif
                                                </flux:button>
                                            @endif
                                        </div>
                                        @if (! empty($scorePreviews[$event->id] ?? []))
                                            <div class="mt-4 rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                                @foreach ($scorePreviews[$event->id] as $preview)
                                                    <div class="flex justify-between gap-3 py-1"><span>{{ $preview['name'] }}</span><strong>{{ $preview['points'] }} pts</strong></div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                    @if (in_array($event->effectiveStatus()->value, ['draft', 'open', 'locked'], true))
                                        <flux:button class="mt-3" size="sm" variant="danger" wire:click="startCancellation({{ $event->id }})">Annuler l’événement</flux:button>
                                    @endif
                                </div>
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:card><div class="py-10 text-center text-sm text-zinc-500">Aucune ronde locale pour ce pool.</div></flux:card>
        @endforelse

        @if ($canManage)
            <flux:modal wire:model.self="showRoundModal" class="md:w-[32rem]">
                <form wire:submit="createRound" class="space-y-5">
                    <div>
                        <flux:heading size="lg">Nouvelle ronde locale</flux:heading>
                        <flux:text class="mt-1">Cette ronde appartiendra uniquement à {{ $pool->name }}.</flux:text>
                    </div>
                    <flux:input wire:model="roundForm.name" label="Nom" placeholder="Semaine 1 ou Finale" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="roundForm.starts_at" label="Début" type="datetime-local" />
                        <flux:input wire:model="roundForm.ends_at" label="Fin" type="datetime-local" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createRound">Créer la ronde</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal wire:model.self="showEventModal" class="md:w-[48rem]">
                <form wire:submit="createEvent" class="space-y-5">
                    <div>
                        <flux:heading size="lg">Nouvel événement local</flux:heading>
                        <flux:text class="mt-1">Configurez un événement propre à {{ $pool->name }}.</flux:text>
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
                    <flux:select wire:model="eventForm.result_publication_mode" label="Visibilité du résultat">
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
                        <flux:switch wire:model="eventForm.allow_none" label="Permettre « aucune célébrité »" />
                        @if ($eventForm['answer_source'] === 'houseguests')
                            <flux:switch wire:model="eventForm.include_inactive_houseguests" label="Inclure toutes les célébrités, même inactives" />
                        @endif
                        <flux:switch wire:model="eventForm.allow_negative" label="Permettre un total négatif" />
                        @if (blank($eventForm['event_type_id']))
                            <flux:switch wire:model="eventForm.save_as_template" label="Enregistrer comme modèle réutilisable" />
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createEvent">Créer l’événement</flux:button>
                    </div>
                </form>
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
