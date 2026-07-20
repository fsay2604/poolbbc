<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">Résultats officiels</flux:heading>
            <flux:text class="mt-1">Saisissez, prévisualisez et publiez les réponses officielles sans modifier la structure des rondes.</flux:text>
        </div>

        <div class="flex flex-wrap gap-4">
            <x-action-message on="official-result-recorded">Le résultat est enregistré et attend une publication explicite.</x-action-message>
            <x-action-message on="official-result-amended">Le brouillon du résultat a été modifié.</x-action-message>
            <x-action-message on="official-result-queued">Le pointage est lancé; le résultat sera publié après sa réussite.</x-action-message>
            <x-action-message on="official-result-retried">La reprise du pointage officiel a été lancée.</x-action-message>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="lg">Événements prêts pour un résultat</flux:heading>
                <flux:text class="mt-1 text-sm">Seuls les événements verrouillés ou déjà publiés apparaissent ici.</flux:text>
            </div>
            <flux:select wire:model.live="seasonId" label="Saison affichée" class="sm:w-64">
                @foreach ($seasons as $season)
                    <flux:select.option :value="$season->id">{{ $season->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @forelse ($rounds as $round)
            <flux:card wire:key="official-result-round-{{ $round->id }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading size="lg">{{ $round->name }}</flux:heading>
                    <flux:badge color="zinc">{{ $round->events->count() }} événement(s)</flux:badge>
                </div>
                <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($round->events as $event)
                        @php
                            $recoverableResult = $event->results->first(
                                fn ($result) => in_array($result->status, ['pending', 'failed'], true),
                            );
                        @endphp
                        <div class="grid gap-4 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center" wire:key="official-result-event-{{ $event->id }}">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="font-medium">{{ $event->name }}</div>
                                    <flux:badge :color="match ($event->effectiveStatus()->value) { 'published' => 'green', 'result_entered', 'locked' => 'amber', default => 'zinc' }">
                                        {{ $event->effectiveStatus()->label() }}
                                    </flux:badge>
                                </div>
                                <div class="mt-1 text-sm text-zinc-500">{{ $event->question }}</div>

                                @if ($event->results->isNotEmpty())
                                    <flux:accordion class="mt-3" transition>
                                        <flux:accordion.item>
                                            <flux:accordion.heading>Historique des résultats · {{ $event->results->count() }} version(s)</flux:accordion.heading>
                                            <flux:accordion.content>
                                                <div class="flex flex-col gap-3 text-sm">
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

                            <div class="flex max-w-xl flex-wrap gap-2">
                                @if ($event->draftResult)
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
                                        <flux:callout.heading>{{ $recoverableResult->status === 'failed' ? 'Échec du pointage officiel' : 'Pointage officiel en attente' }}</flux:callout.heading>
                                        <flux:callout.text>La reprise ne recalculera que les pools dont le pointage demeure incomplet.</flux:callout.text>
                                    </flux:callout>
                                    <flux:button size="sm" wire:click="retryPublication({{ $event->id }})" wire:loading.attr="disabled" wire:target="retryPublication({{ $event->id }})" variant="primary">
                                        Reprendre le pointage
                                    </flux:button>
                                @else
                                    <flux:button size="sm" wire:click="startResult({{ $event->id }})" variant="primary">
                                        {{ $event->latestResult ? 'Corriger le résultat' : 'Saisir le résultat' }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:card class="py-12 text-center">
                <flux:heading>Aucun événement prêt</flux:heading>
                <flux:text class="mt-2">Les événements apparaîtront ici après leur verrouillage.</flux:text>
            </flux:card>
        @endforelse

        @php
            $resultEvent = $rounds->flatMap->events->firstWhere('id', $resultEventId);
        @endphp
        <flux:modal wire:model.self="showResultModal" class="md:w-[48rem]">
            @if ($resultEvent)
                <div class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg">{{ $resultEvent->draftResult ? 'Modifier' : ($resultEvent->latestResult ? 'Corriger' : 'Saisir') }} · {{ $resultEvent->name }}</flux:heading>
                        <flux:text class="mt-1">Prévisualisez l’impact sur chaque membre avant de confirmer le résultat.</flux:text>
                    </div>
                    <flux:checkbox.group wire:model="resultOptionIds" label="Résultat officiel" class="grid gap-2 sm:grid-cols-2">
                        @foreach ($resultEvent->options as $option)
                            <flux:checkbox :value="$option->id" :label="$option->label" wire:key="official-result-option-{{ $option->id }}" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="resultOptionIds" />
                    <flux:error name="result" />
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
                                            <flux:table.row :key="$index" wire:key="official-preview-row-{{ $index }}">
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
                            <flux:modal.close><flux:button type="button">Annuler</flux:button></flux:modal.close>
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
