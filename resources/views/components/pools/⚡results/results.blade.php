<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Résultats · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Saisissez et publiez les résultats locaux; les résultats officiels demeurent communs à la saison.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <div class="flex flex-wrap gap-4">
            <x-action-message on="result-recorded">Le résultat est enregistré et attend une publication explicite.</x-action-message>
            <x-action-message on="result-amended">Le brouillon du résultat a été modifié.</x-action-message>
            <x-action-message on="result-published">Le résultat et les points ont été publiés.</x-action-message>
        </div>

        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Résultats propres au pool</flux:heading>
                <flux:text class="mt-1 text-sm">Les événements locaux verrouillés apparaissent ici. Chaque publication conserve son historique de versions.</flux:text>
            </div>

            @forelse ($rounds as $round)
                <flux:card wire:key="local-result-round-{{ $round->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:heading size="lg">{{ $round->name }}</flux:heading>
                        <flux:badge color="zinc">{{ $round->events->count() }} événement(s)</flux:badge>
                    </div>

                    <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($round->events as $event)
                            @php
                                $isEditingDraft = in_array($event->id, $editingDraftResultIds, true);
                            @endphp
                            <div class="grid gap-4 py-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.9fr)]" wire:key="local-result-event-{{ $event->id }}">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="font-medium">{{ $event->name }}</div>
                                        <flux:badge :color="match ($event->effectiveStatus()->value) { 'published' => 'green', 'result_entered', 'locked' => 'amber', default => 'zinc' }">
                                            {{ $event->effectiveStatus()->label() }}
                                        </flux:badge>
                                    </div>
                                    @if ($event->question)
                                        <div class="mt-1 text-sm text-zinc-500">{{ $event->question }}</div>
                                    @endif

                                    @if ($event->latestResult)
                                        <flux:callout icon="check-circle" color="green" class="mt-3">
                                            <flux:callout.heading>Résultat publié · version {{ $event->latestResult->version }}</flux:callout.heading>
                                            <flux:callout.text>{{ $event->latestResult->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</flux:callout.text>
                                        </flux:callout>
                                    @endif

                                    @if ($event->results->isNotEmpty())
                                        <flux:accordion class="mt-3" transition>
                                            <flux:accordion.item>
                                                <flux:accordion.heading>Historique · {{ $event->results->count() }} version(s)</flux:accordion.heading>
                                                <flux:accordion.content>
                                                    <div class="space-y-3 text-sm">
                                                        @foreach ($event->results as $result)
                                                            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="local-result-history-{{ $result->id }}">
                                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                                    <span class="font-medium">Version {{ $result->version }} · {{ $result->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</span>
                                                                    <flux:badge size="sm" :color="$result->status === 'published' ? 'green' : 'amber'">{{ $result->status }}</flux:badge>
                                                                </div>
                                                                <div class="mt-1 text-xs text-zinc-500">{{ ($result->published_at ?? $result->created_at)->translatedFormat('j M Y H:i') }} · {{ $result->createdBy?->name }}</div>
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

                                @if ($canManageLocalResults)
                                    <div>
                                        @if ($event->draftResult && ! $isEditingDraft)
                                            <flux:callout icon="clock" color="amber">
                                                <flux:callout.heading>Résultat enregistré, non publié</flux:callout.heading>
                                                <flux:callout.text>{{ $event->draftResult->options->pluck('label')->implode(' · ') ?: 'Résultat vide' }}</flux:callout.text>
                                            </flux:callout>
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <flux:button size="sm" wire:click="startAmendingRecordedResult({{ $event->id }})" icon="pencil-square">Modifier</flux:button>
                                                <flux:button size="sm" wire:click="publishRecordedResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="publishRecordedResult({{ $event->id }})" variant="primary">Publier</flux:button>
                                            </div>
                                        @else
                                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                                <flux:checkbox.group wire:model="resultSelections.{{ $event->id }}" label="Réponses finales">
                                                    @foreach ($event->options as $option)
                                                        <flux:checkbox :value="$option->id" :label="$option->label" wire:key="local-result-option-{{ $event->id }}-{{ $option->id }}" />
                                                    @endforeach
                                                </flux:checkbox.group>
                                                <flux:error :name="'resultSelections.'.$event->id" />
                                                <flux:error name="result" />

                                                @if ($event->latestResult || ($event->draftResult && $isEditingDraft))
                                                    <flux:textarea wire:model="correctionReasons.{{ $event->id }}" label="Justification de la correction" class="mt-3" rows="2" />
                                                    <flux:error name="correction_reason" />
                                                @endif

                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <flux:button size="sm" wire:click="previewResult({{ $event->id }})" icon="eye">Aperçu des points</flux:button>
                                                    @if (isset($resultPreviewFingerprints[$event->id]))
                                                        @if ($event->draftResult && $isEditingDraft)
                                                            <flux:button size="sm" variant="primary" wire:click="amendRecordedResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="amendRecordedResult({{ $event->id }})">Enregistrer les modifications</flux:button>
                                                        @else
                                                            <flux:button size="sm" variant="primary" wire:click="publishResult({{ $event->id }})" wire:loading.attr="disabled" wire:target="publishResult({{ $event->id }})">
                                                                {{ $event->result_publication_mode->value === 'manual' ? 'Enregistrer le résultat' : 'Publier le résultat' }}
                                                            </flux:button>
                                                        @endif
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
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @empty
                <flux:card class="py-12 text-center">
                    <flux:heading>Aucun événement local prêt</flux:heading>
                    <flux:text class="mt-2">Les événements apparaîtront ici après leur verrouillage.</flux:text>
                </flux:card>
            @endforelse
        </div>

        @can('admin')
            <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <livewire:admin.official-results :pool="$pool" :embedded="true" />
            </div>
        @endcan
    </div>
</section>
