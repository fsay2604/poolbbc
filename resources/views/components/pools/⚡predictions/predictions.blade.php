@php
    $predictionRounds = $this->predictionRounds;
@endphp

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">Prédictions · {{ $pool->name }}</flux:heading>
            <flux:text class="mt-1">Répondez aux événements officiels et à ceux propres au pool au même endroit.</flux:text>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <x-action-message on="prediction-autosaved">Brouillon enregistré automatiquement.</x-action-message>
        <x-action-message on="prediction-submitted">Prédiction soumise. Elle reste modifiable jusqu’à l’échéance.</x-action-message>

        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading>Progression</flux:heading>
                    <flux:text class="mt-1 text-sm">{{ $submittedCount }} événement{{ $submittedCount > 1 ? 's' : '' }} soumis sur {{ $totalCount }}</flux:text>
                </div>
                <flux:badge :color="$submittedCount === $totalCount && $totalCount > 0 ? 'green' : 'amber'">
                    {{ $totalCount > 0 ? round(($submittedCount / $totalCount) * 100) : 0 }} %
                </flux:badge>
            </div>

            @if ($missingPredictions !== [])
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <div class="font-semibold">À compléter avant de quitter</div>
                    <div class="mt-1">{{ implode(' · ', $missingPredictions) }}</div>
                </div>
            @endif
        </flux:card>

        @forelse ($predictionRounds as $round)
            <div class="space-y-4" wire:key="round-{{ $round['key'] }}">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">{{ $round['name'] }}</flux:heading>
                    <flux:badge color="zinc">{{ $round['source_label'] }}</flux:badge>
                    <flux:badge color="zinc">{{ $round['events']->count() }} événement{{ $round['events']->count() > 1 ? 's' : '' }}</flux:badge>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    @foreach ($round['events'] as $event)
                        <flux:card wire:key="prediction-event-{{ $event->key }}" class="flex flex-col">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <flux:heading>{{ $event->name }}</flux:heading>
                                    @if ($event->question)
                                        <flux:text class="mt-1 text-sm">{{ $event->question }}</flux:text>
                                    @endif
                                </div>
                                <flux:badge :color="$event->progressColor">{{ $event->progressLabel }}</flux:badge>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>{{ $event->selectionLimitLabel }}</span>
                                @if ($event->deadlineLabel)
                                    <span>· Échéance {{ $event->deadlineLabel }}</span>
                                @endif
                                <span>· {{ $event->effectiveStatusLabel }}</span>
                            </div>

                            <div class="mt-5 flex-1">
                                @if ($event->isSingleSelection)
                                    <flux:radio.group
                                        wire:model.live="selections.{{ $event->key }}"
                                        :disabled="! $event->canSave"
                                        label="Votre réponse"
                                        variant="cards"
                                        class="grid gap-2 sm:grid-cols-2"
                                    >
                                        @foreach ($event->options as $option)
                                            <flux:radio
                                                wire:key="{{ $event->key }}-option-{{ $option['id'] }}"
                                                :value="$option['id']"
                                                :label="$option['label']"
                                            />
                                        @endforeach
                                    </flux:radio.group>
                                @else
                                    <flux:checkbox.group
                                        wire:model.live="selections.{{ $event->key }}"
                                        :disabled="! $event->canSave"
                                        label="Vos réponses"
                                        class="grid gap-2 sm:grid-cols-2"
                                    >
                                        @foreach ($event->options as $option)
                                            <flux:checkbox
                                                wire:key="{{ $event->key }}-option-{{ $option['id'] }}"
                                                :value="$option['id']"
                                                :label="$option['label']"
                                            />
                                        @endforeach
                                    </flux:checkbox.group>
                                @endif

                                @error("selections.{$event->key}")
                                    <div class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</div>
                                @enderror

                                @if ($event->showResult)
                                    <div class="mt-5 space-y-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800/70">
                                        <div>
                                            <div class="font-semibold">{{ $event->resultLabel }}</div>
                                            <div class="mt-1 text-zinc-600 dark:text-zinc-300">
                                                @if (! $event->hasPublishedResult)
                                                    En attente de publication
                                                @elseif ($event->resultOptionLabels === [])
                                                    Aucune sélection
                                                @else
                                                    {{ implode(' · ', $event->resultOptionLabels) }}
                                                @endif
                                            </div>
                                        </div>

                                        @if ($event->revealedPredictions !== [])
                                            <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                                <div class="font-semibold">Réponses révélées</div>
                                                <div class="mt-2 space-y-1">
                                                    @foreach ($event->revealedPredictions as $revealedPrediction)
                                                        <div class="flex justify-between gap-3" wire:key="{{ $event->key }}-revealed-{{ $loop->index }}">
                                                            <span>{{ $revealedPrediction['member_name'] }}</span>
                                                            <span class="text-end text-zinc-600 dark:text-zinc-300">
                                                                {{ implode(' · ', $revealedPrediction['option_labels']) }}
                                                            </span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="mt-5 flex items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <div
                                    class="text-xs text-zinc-500"
                                    wire:loading
                                    wire:target="selections.{{ $event->key }},submit('{{ $event->key }}')"
                                >
                                    Enregistrement…
                                </div>
                                <flux:button
                                    wire:click="submit('{{ $event->key }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="submit('{{ $event->key }}')"
                                    :disabled="! $event->canSubmit"
                                    variant="primary"
                                    class="ms-auto"
                                >
                                    {{ $event->submitLabel }}
                                </flux:button>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:card class="py-12 text-center">
                <flux:heading>Aucune prédiction disponible</flux:heading>
                <flux:text class="mt-2">Les événements officiels et ceux du pool apparaîtront ici lorsqu’ils accepteront des prédictions.</flux:text>
            </flux:card>
        @endforelse
    </div>
</section>
