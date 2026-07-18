<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">Rondes et résultats officiels</flux:heading>
            <flux:text class="mt-1">Une définition canonique alimente tous les pools de la saison.</flux:text>
        </div>

        <div class="flex flex-wrap gap-4">
            <x-action-message on="official-round-created">La ronde officielle et ses événements ont été créés.</x-action-message>
            <x-action-message on="official-event-updated">L’événement officiel a été mis à jour.</x-action-message>
            <x-action-message on="official-event-cancelled">L’événement a été annulé sans attribuer de points.</x-action-message>
            <x-action-message on="official-result-recorded">Le résultat est enregistré et attend une publication explicite.</x-action-message>
            <x-action-message on="official-result-amended">Le brouillon du résultat a été modifié.</x-action-message>
            <x-action-message on="official-result-queued">Le pointage est lancé; le résultat sera publié après sa réussite.</x-action-message>
        </div>

        <flux:card>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Assistant de création</flux:heading>
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
                    <div class="md:col-span-2 flex justify-end">
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">Réviser la ronde</flux:button>
                    </div>
                </form>
            @else
                <div class="mt-6 space-y-4">
                    <div class="grid gap-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800 md:grid-cols-2">
                        <div><span class="text-zinc-500">Nom</span><div class="font-medium">{{ $roundName }}</div></div>
                        <div><span class="text-zinc-500">Modèle</span><div class="font-medium">{{ str_replace('_', ' ', $template) }}</div></div>
                        <div><span class="text-zinc-500">Ouverture</span><div class="font-medium">{{ $opensAt }} · {{ config('app.timezone') }}</div></div>
                        <div><span class="text-zinc-500">Verrouillage</span><div class="font-medium">{{ $locksAt }} · {{ config('app.timezone') }}</div></div>
                    </div>
                    <flux:text class="text-sm">Chaque copie devient indépendante : vous pourrez adapter ses événements tant qu’ils ne sont pas ouverts.</flux:text>
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
                <flux:text class="mt-1 text-sm">Les heures ci-dessous utilisent {{ config('app.timezone') }}.</flux:text>
            </div>
            <flux:select wire:model.live="seasonId" label="Saison affichée" class="sm:w-64">
                @foreach ($seasons as $season)
                    <flux:select.option :value="$season->id">{{ $season->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @forelse ($rounds as $round)
            <flux:card wire:key="official-round-{{ $round->id }}">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ $round->name }}</flux:heading>
                    <flux:badge color="zinc">{{ $round->events->count() }} événements</flux:badge>
                </div>
                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($round->events as $event)
                        @php
                            $recoverableResult = $event->results->first(
                                fn ($result) => in_array($result->status, ['pending', 'failed'], true),
                            );
                        @endphp
                        <div class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center" wire:key="official-event-{{ $event->id }}">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="font-medium">{{ $event->name }}</div>
                                    <flux:badge :color="match ($event->effectiveStatus()->value) { 'published' => 'green', 'cancelled' => 'red', 'locked', 'result_entered' => 'amber', 'open' => 'blue', default => 'zinc' }">
                                        {{ $event->effectiveStatus()->label() }}
                                    </flux:badge>
                                    <flux:badge color="zinc">{{ $event->poolEvents->count() }} pools</flux:badge>
                                </div>
                                <div class="mt-1 text-sm text-zinc-500">
                                    {{ $event->question }}
                                    @if ($event->locks_at)
                                        · verrou {{ $event->locks_at->format('d/m/Y H:i') }} {{ config('app.timezone') }}
                                    @endif
                                </div>
                                @if ($event->results->isNotEmpty())
                                    <flux:accordion class="mt-3" transition>
                                        <flux:accordion.item>
                                            <flux:accordion.heading>Historique des résultats · {{ $event->results->count() }} version(s)</flux:accordion.heading>
                                            <flux:accordion.content>
                                                <div class="space-y-3 text-sm">
                                                    @foreach ($event->results as $result)
                                                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="official-result-history-{{ $result->id }}">
                                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                                <span class="font-medium">Version {{ $result->version }} · {{ $result->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</span>
                                                                <flux:badge size="sm" :color="$result->status === 'published' ? 'green' : ($result->status === 'failed' ? 'red' : 'amber')">{{ $result->status }}</flux:badge>
                                                            </div>
                                                            <div class="mt-1 text-xs text-zinc-500">
                                                                {{ ($result->published_at ?? $result->created_at)->translatedFormat('j M Y H:i') }} · {{ $result->creator?->name }}
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
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($event->effectiveStatus()->value === 'draft')
                                    <flux:button size="sm" wire:click="startEdit({{ $event->id }})" icon="pencil-square">Adapter</flux:button>
                                    <flux:button size="sm" wire:click="openEvent({{ $event->id }})" variant="primary">Ouvrir</flux:button>
                                @elseif ($event->effectiveStatus()->value === 'open')
                                    <flux:button size="sm" wire:click="lockEvent({{ $event->id }})">Verrouiller</flux:button>
                                @elseif ($event->draftResult)
                                    <flux:callout icon="clock" color="amber" class="w-full">
                                        <flux:callout.heading>Résultat enregistré, non publié</flux:callout.heading>
                                        <flux:callout.text>{{ $event->draftResult->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</flux:callout.text>
                                    </flux:callout>
                                    <flux:button size="sm" wire:click="startResult({{ $event->id }})" icon="pencil-square">Modifier</flux:button>
                                    <flux:button size="sm" wire:click="publishRecordedResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="publishRecordedResult({{ $event->id }})" variant="primary">
                                        Publier et lancer le pointage
                                    </flux:button>
                                @elseif ($recoverableResult)
                                    <flux:callout
                                        :icon="$recoverableResult->status === 'failed' ? 'exclamation-triangle' : 'clock'"
                                        :color="$recoverableResult->status === 'failed' ? 'red' : 'amber'"
                                        class="w-full"
                                    >
                                        <flux:callout.heading>
                                            {{ $recoverableResult->status === 'failed' ? 'Échec du pointage officiel' : 'Pointage officiel en attente' }}
                                        </flux:callout.heading>
                                        <flux:callout.text>La reprise ne recalculera que les pools dont le pointage demeure incomplet.</flux:callout.text>
                                    </flux:callout>
                                    <flux:button
                                        size="sm"
                                        wire:click="retryPublication({{ $event->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="retryPublication({{ $event->id }})"
                                        variant="primary"
                                    >
                                        Reprendre le pointage
                                    </flux:button>
                                @elseif (in_array($event->effectiveStatus()->value, ['locked', 'published'], true))
                                    <flux:button size="sm" wire:click="startResult({{ $event->id }})" variant="primary">
                                        {{ $event->latestResult ? 'Corriger le résultat' : 'Saisir le résultat' }}
                                    </flux:button>
                                @endif
                                @if (in_array($event->effectiveStatus()->value, ['draft', 'open', 'locked'], true))
                                    <flux:button size="sm" variant="danger" wire:click="startCancellation({{ $event->id }})">Annuler</flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:card class="py-12 text-center">
                <flux:heading>Aucune ronde officielle</flux:heading>
                <flux:text class="mt-2">Utilisez l’assistant pour créer la première ronde.</flux:text>
            </flux:card>
        @endforelse

        <flux:modal wire:model.self="showEditModal" class="md:w-[36rem]">
            <form wire:submit="saveEvent" class="space-y-5">
                <div>
                    <flux:heading size="lg">Adapter l’événement avant ouverture</flux:heading>
                    <flux:text class="mt-1">Ces règles seront gelées dès l’ouverture.</flux:text>
                </div>
                <flux:input wire:model="eventForm.name" label="Nom" />
                <flux:textarea wire:model="eventForm.question" label="Question" rows="2" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="eventForm.opens_at" type="datetime-local" label="Ouverture" />
                    <flux:input wire:model="eventForm.locks_at" type="datetime-local" label="Verrouillage" />
                    <flux:input wire:model="eventForm.prediction_min_selections" type="number" min="0" label="Minimum de prédictions" />
                    <flux:input wire:model="eventForm.prediction_max_selections" type="number" min="0" label="Maximum de prédictions" />
                    <flux:input wire:model="eventForm.result_min_selections" type="number" min="0" label="Minimum de résultats" />
                    <flux:input wire:model="eventForm.result_max_selections" type="number" min="0" label="Maximum de résultats" />
                    <flux:select wire:model="eventForm.default_mode" label="Mode proposé aux pools">
                        @foreach (\App\Enums\EventMode::cases() as $mode)
                            <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="eventForm.result_publication_mode" label="Publication du résultat">
                        <flux:select.option value="immediate">Immédiate après confirmation</flux:select.option>
                        <flux:select.option value="manual">Manuelle après enregistrement</flux:select.option>
                    </flux:select>
                </div>
                <div class="flex flex-wrap gap-6">
                    <flux:switch wire:model="eventForm.include_inactive_houseguests" label="Inclure toutes les célébrités, même inactives" />
                    <flux:switch wire:model="eventForm.allow_none" label="Permettre « aucune célébrité »" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button x-on:click="$wire.showEditModal = false">Annuler</flux:button>
                    <flux:button type="submit" variant="primary">Enregistrer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showCancellationModal" class="md:w-[32rem]">
            <form wire:submit="cancelEvent" class="space-y-5">
                <div>
                    <flux:heading size="lg">Annuler l’événement officiel</flux:heading>
                    <flux:text class="mt-1">Aucun point ne sera attribué. La raison restera dans la piste d’audit.</flux:text>
                </div>
                <flux:textarea wire:model="cancellationReason" label="Raison obligatoire" rows="3" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" x-on:click="$wire.showCancellationModal = false">Retour</flux:button>
                    <flux:button type="submit" variant="danger">Confirmer l’annulation</flux:button>
                </div>
            </form>
        </flux:modal>

        @php
            $resultEvent = $rounds->flatMap->events->firstWhere('id', $resultEventId);
        @endphp
        <flux:modal wire:model.self="showResultModal" class="md:w-[48rem]">
            @if ($resultEvent)
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ $resultEvent->draftResult ? 'Modifier' : ($resultEvent->latestResult ? 'Corriger' : 'Saisir') }} · {{ $resultEvent->name }}</flux:heading>
                        <flux:text class="mt-1">Prévisualisez l’impact sur chaque membre avant de confirmer le résultat.</flux:text>
                    </div>
                    <flux:checkbox.group wire:model="resultOptionIds" label="Résultat officiel" class="grid gap-2 sm:grid-cols-2">
                        @foreach ($resultEvent->options as $option)
                            <flux:checkbox :value="$option->id" :label="$option->label" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="resultOptionIds" />
                    @if ($resultEvent->latestResult)
                        <flux:textarea wire:model="correctionReason" label="Justification obligatoire de la correction" rows="2" />
                    @endif
                    <flux:button wire:click="previewResult" icon="eye">Générer l’aperçu</flux:button>

                    @if ($resultPreviewFingerprint !== null)
                        @if ($previewRows !== [])
                            <div class="max-h-72 overflow-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                                <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Pool</flux:table.column>
                                    <flux:table.column>Membre</flux:table.column>
                                    <flux:table.column align="end">Équipe</flux:table.column>
                                    <flux:table.column align="end">Prédiction</flux:table.column>
                                    <flux:table.column align="end">Total</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($previewRows as $index => $row)
                                        <flux:table.row :key="$index">
                                            <flux:table.cell>{{ $row['pool'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $row['member'] }}</flux:table.cell>
                                            <flux:table.cell align="end">{{ $row['roster_points'] }}</flux:table.cell>
                                            <flux:table.cell align="end">{{ $row['prediction_points'] }}</flux:table.cell>
                                            <flux:table.cell align="end" variant="strong">{{ $row['total_points'] }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                                </flux:table>
                            </div>
                        @else
                            <flux:callout icon="check-circle" color="green">Aucun membre ne recevra de points pour cette sélection.</flux:callout>
                        @endif
                        <div class="flex justify-end gap-2">
                            <flux:button x-on:click="$wire.showResultModal = false">Annuler</flux:button>
                            @if ($resultEvent->draftResult)
                                <flux:button wire:click="amendResult" wire:loading.attr="disabled" wire:target="amendResult" variant="primary">Modifier le brouillon</flux:button>
                            @else
                                <flux:button wire:click="publishResult" wire:loading.attr="disabled" wire:target="publishResult" variant="primary">
                                    {{ $resultEvent->result_publication_mode->value === 'manual' ? 'Confirmer et enregistrer' : 'Confirmer et publier' }}
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </flux:modal>
    </div>
</section>
