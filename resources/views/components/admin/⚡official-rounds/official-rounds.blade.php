<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">Rondes et événements officiels</flux:heading>
            <flux:text class="mt-1">Créez la structure canonique de la saison, puis gérez chaque événement avant son ouverture.</flux:text>
        </div>

        <div class="flex flex-wrap gap-4">
            <x-action-message on="official-round-created">La ronde officielle et ses événements ont été créés.</x-action-message>
            <x-action-message on="official-round-updated">La ronde officielle a été mise à jour.</x-action-message>
            <x-action-message on="official-round-deleted">La ronde officielle a été supprimée.</x-action-message>
            <x-action-message on="official-event-created">L’événement officiel a été créé.</x-action-message>
            <x-action-message on="official-event-updated">L’événement officiel a été mis à jour.</x-action-message>
            <x-action-message on="official-event-deleted">L’événement officiel a été supprimé.</x-action-message>
            <x-action-message on="official-event-cancelled">L’événement a été annulé sans attribuer de points.</x-action-message>
        </div>

        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Assistant de création d’une ronde</flux:heading>
                    <flux:text class="mt-1 text-sm">Étape {{ $wizardStep }} sur 2 · fuseau {{ config('app.timezone') }}</flux:text>
                </div>
                <flux:badge color="blue">Modèle cloné en profondeur</flux:badge>
            </div>

            @if ($wizardStep === 1)
                <form wire:submit="reviewWizard" class="mt-6 grid gap-4 md:grid-cols-2">
                    <flux:select wire:model="seasonId" label="Saison">
                        @foreach ($seasons as $season)
                            <flux:select.option :value="$season->id">{{ $season->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="template" label="Structure de la ronde">
                        <flux:select.option value="standard">Semaine standard</flux:select.option>
                        <flux:select.option value="no_veto">Sans veto</flux:select.option>
                        <flux:select.option value="double_eviction">Double éviction</flux:select.option>
                        <flux:select.option value="finale">Finale</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="roundName" label="Nom de la ronde" placeholder="Semaine 4" />
                    <div class="hidden md:block"></div>
                    <flux:input wire:model="opensAt" type="datetime-local" label="Ouverture ({{ config('app.timezone') }})" />
                    <flux:input wire:model="locksAt" type="datetime-local" label="Verrouillage ({{ config('app.timezone') }})" />
                    <div class="flex justify-end md:col-span-2">
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">Réviser la ronde</flux:button>
                    </div>
                </form>
            @else
                <div class="mt-6 flex flex-col gap-4">
                    <div class="grid gap-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800 md:grid-cols-2">
                        <div><span class="text-zinc-500">Nom</span><div class="font-medium">{{ $roundName }}</div></div>
                        <div><span class="text-zinc-500">Modèle</span><div class="font-medium">{{ str_replace('_', ' ', $template) }}</div></div>
                        <div><span class="text-zinc-500">Ouverture</span><div class="font-medium">{{ $opensAt }} · {{ config('app.timezone') }}</div></div>
                        <div><span class="text-zinc-500">Verrouillage</span><div class="font-medium">{{ $locksAt }} · {{ config('app.timezone') }}</div></div>
                    </div>
                    <flux:text class="text-sm">Chaque copie devient indépendante et peut être adaptée tant qu’elle n’est pas ouverte.</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="backToWizard">Modifier</flux:button>
                        <flux:button wire:click="createRound" variant="primary">Créer la ronde officielle</flux:button>
                    </div>
                </div>
            @endif
        </flux:card>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="lg">Calendrier officiel</flux:heading>
                <flux:text class="mt-1 text-sm">Les dates de la ronde sont informatives; modifier une ronde ne déplace pas automatiquement ses événements.</flux:text>
            </div>
            <flux:select wire:model.live="seasonId" label="Saison affichée" class="sm:w-64">
                @foreach ($seasons as $season)
                    <flux:select.option :value="$season->id">{{ $season->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:error name="eventForm.event_type_id" />

        @forelse ($rounds as $round)
            <flux:card wire:key="official-round-{{ $round->id }}">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $round->name }}</flux:heading>
                            <flux:badge color="zinc">{{ $round->events->count() }} événements</flux:badge>
                        </div>
                        <flux:text class="mt-1 text-sm">
                            @if ($round->starts_at || $round->ends_at)
                                {{ $round->starts_at?->format('d/m/Y H:i') ?? '—' }} → {{ $round->ends_at?->format('d/m/Y H:i') ?? '—' }} · {{ config('app.timezone') }}
                            @else
                                Aucune période informative définie.
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="pencil-square" wire:click="startRoundEdit({{ $round->id }})">Modifier</flux:button>
                        <flux:button size="sm" icon="plus" variant="primary" wire:click="startCreateEvent({{ $round->id }})">Ajouter un événement</flux:button>
                        <flux:button size="sm" icon="trash" variant="danger" wire:click="startRoundDeletion({{ $round->id }})">Supprimer</flux:button>
                    </div>
                </div>

                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($round->events as $event)
                        <div class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center" wire:key="official-event-{{ $event->id }}">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="font-medium">{{ $event->name }}</div>
                                    <flux:badge :color="match ($event->effectiveStatus()->value) { 'published' => 'green', 'cancelled' => 'red', 'locked', 'result_entered' => 'amber', 'open' => 'blue', default => 'zinc' }">
                                        {{ $event->effectiveStatus()->label() }}
                                    </flux:badge>
                                    <flux:badge color="zinc">{{ $event->pool_events_count }} pools</flux:badge>
                                    @if ($event->eventType)
                                        <flux:badge color="blue">{{ $event->eventType->name }}</flux:badge>
                                    @endif
                                </div>
                                <div class="mt-1 text-sm text-zinc-500">
                                    {{ $event->question }}
                                    @if ($event->locks_at)
                                        · verrou {{ $event->locks_at->format('d/m/Y H:i') }} {{ config('app.timezone') }}
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($event->effectiveStatus() === \App\Enums\EventStatus::Draft)
                                    <flux:button size="sm" wire:click="startEdit({{ $event->id }})" icon="pencil-square">Adapter</flux:button>
                                    <flux:button size="sm" wire:click="openEvent({{ $event->id }})" variant="primary">Ouvrir</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="startEventDeletion({{ $event->id }})">Supprimer</flux:button>
                                @elseif ($event->effectiveStatus() === \App\Enums\EventStatus::Open)
                                    <flux:button size="sm" wire:click="lockEvent({{ $event->id }})">Verrouiller</flux:button>
                                @endif
                                @if (in_array($event->effectiveStatus(), [\App\Enums\EventStatus::Draft, \App\Enums\EventStatus::Open, \App\Enums\EventStatus::Locked], true))
                                    <flux:button size="sm" variant="danger" wire:click="startCancellation({{ $event->id }})">Annuler</flux:button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-500">Cette ronde ne contient encore aucun événement.</div>
                    @endforelse
                </div>
            </flux:card>
        @empty
            <flux:card class="py-12 text-center">
                <flux:heading>Aucune ronde officielle</flux:heading>
                <flux:text class="mt-2">Utilisez l’assistant pour créer la première ronde.</flux:text>
            </flux:card>
        @endforelse

        <flux:modal wire:model.self="showRoundModal" class="md:w-[36rem]">
            <form wire:submit="saveRound" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">Modifier la ronde officielle</flux:heading>
                    <flux:text class="mt-1">Ces dates n’affectent pas les échéances propres aux événements.</flux:text>
                </div>
                <flux:input wire:model="roundForm.name" label="Nom" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="roundForm.starts_at" type="datetime-local" label="Début informatif" />
                    <flux:input wire:model="roundForm.ends_at" type="datetime-local" label="Fin informative" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveRound">Enregistrer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showEventModal" class="md:w-[42rem]">
            <form wire:submit="saveEvent" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $creatingRoundId !== null ? 'Ajouter un événement officiel' : 'Adapter l’événement avant ouverture' }}</flux:heading>
                    <flux:text class="mt-1">Les règles sont gelées dès l’ouverture ou la première réponse.</flux:text>
                </div>
                <flux:error name="eventForm" />
                @if ($creatingRoundId !== null)
                    <flux:select wire:model.live="eventForm.event_type_id" label="Type d’événement standard">
                        @foreach ($eventTypes as $eventType)
                            <flux:select.option :value="$eventType->id">{{ $eventType->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:input wire:model="eventForm.name" label="Nom" />
                <flux:textarea wire:model="eventForm.question" label="Question" rows="2" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="eventForm.opens_at" type="datetime-local" label="Ouverture" />
                    <flux:input wire:model="eventForm.locks_at" type="datetime-local" label="Verrouillage" />
                    <flux:input wire:model="eventForm.prediction_min_selections" type="number" min="0" max="20" label="Minimum de prédictions" />
                    <flux:input wire:model="eventForm.prediction_max_selections" type="number" min="1" max="20" label="Maximum de prédictions" />
                    <flux:input wire:model="eventForm.result_min_selections" type="number" min="0" max="20" label="Minimum de résultats" />
                    <flux:input wire:model="eventForm.result_max_selections" type="number" min="1" max="20" label="Maximum de résultats" />
                    <flux:select wire:model="eventForm.default_mode" label="Mode proposé aux pools">
                        @foreach (\App\Enums\EventMode::cases() as $mode)
                            <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="eventForm.result_publication_mode" label="Publication du résultat">
                        @foreach (\App\Enums\ResultPublicationMode::cases() as $publicationMode)
                            <flux:select.option :value="$publicationMode->value">{{ $publicationMode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="flex flex-wrap gap-6">
                    <flux:switch wire:model="eventForm.include_inactive_houseguests" label="Inclure les candidats inactifs" />
                    <flux:switch wire:model="eventForm.allow_none" label="Permettre « aucun candidat »" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEvent">Enregistrer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showCancellationModal" class="md:w-[32rem]">
            <form wire:submit="cancelEvent" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">Annuler l’événement officiel</flux:heading>
                    <flux:text class="mt-1">Aucun point ne sera attribué. La raison restera dans la piste d’audit.</flux:text>
                </div>
                <flux:textarea wire:model="cancellationReason" label="Raison obligatoire" rows="3" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button">Retour</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">Confirmer l’annulation</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showEventDeletionModal" class="md:w-[32rem]">
            <form wire:submit="deleteEvent" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">Supprimer l’événement</flux:heading>
                    <flux:text class="mt-1">« {{ $deletingEventName }} » sera supprimé de tous les pools. Un événement ouvert ou utilisé doit plutôt être annulé.</flux:text>
                </div>
                <flux:error name="eventDeletion" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button">Retour</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteEvent">Supprimer définitivement</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showRoundDeletionModal" class="md:w-[32rem]">
            <form wire:submit="deleteRound" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">Supprimer la ronde</flux:heading>
                    <flux:text class="mt-1">« {{ $deletingRoundName }} » et ses événements brouillons inutilisés seront supprimés.</flux:text>
                </div>
                <flux:error name="roundDeletion" />
                <flux:error name="eventDeletion" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button">Retour</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteRound">Supprimer définitivement</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
