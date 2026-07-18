@php
    $rounds = $this->rounds;
@endphp

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">Prédictions · {{ $pool->name }}</flux:heading>
            <flux:text class="mt-1">Enregistrez au fil de l’eau, puis soumettez chaque réponse avant son verrouillage.</flux:text>
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
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-accent transition-all" style="width: {{ $totalCount > 0 ? ($submittedCount / $totalCount) * 100 : 0 }}%"></div>
            </div>

            @if ($missingPredictions !== [])
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <div class="font-semibold">À compléter avant de quitter</div>
                    <div class="mt-1">{{ implode(' · ', $missingPredictions) }}</div>
                </div>
            @endif
        </flux:card>

        @forelse ($rounds as $round)
            <div class="space-y-4" wire:key="round-{{ $round->id }}">
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">{{ $round->name }}</flux:heading>
                    <flux:badge color="zinc">{{ $round->events->count() }} événement{{ $round->events->count() > 1 ? 's' : '' }}</flux:badge>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    @foreach ($round->events as $event)
                        @php
                            $poolEvent = $event->poolEvents->first();
                            $prediction = $poolEvent?->predictions->firstWhere('pool_member_id', $member?->id);
                            $effectiveStatus = $event->effectiveStatus();
                            $isOpen = $effectiveStatus->value === 'open';
                            [$predictionStatusLabel, $predictionStatusColor] = match (true) {
                                $prediction?->status?->value === 'locked' => ['Verrouillée', 'zinc'],
                                $prediction?->status?->value === 'submitted' => ['Soumise', 'green'],
                                $prediction?->status?->value === 'draft' && $isOpen => ['Brouillon', 'amber'],
                                $prediction?->status?->value === 'draft' => ['Brouillon expiré', 'red'],
                                $isOpen => ['À faire', 'amber'],
                                default => ['Non soumise', 'red'],
                            };
                            $isRevealed = in_array($effectiveStatus->value, ['locked', 'result_entered', 'published'], true);
                            $competingPredictionsAreVisible = $effectiveStatus === \App\Enums\EventStatus::Published
                                || ($poolEvent?->visibility === 'after_lock'
                                    && in_array($effectiveStatus, [\App\Enums\EventStatus::Locked, \App\Enums\EventStatus::ResultEntered], true));
                            $revealedPredictions = $competingPredictionsAreVisible
                                ? ($poolEvent?->predictions->filter(
                                    fn ($candidate) => in_array($candidate->status, [\App\Enums\PredictionStatus::Submitted, \App\Enums\PredictionStatus::Locked], true)
                                ) ?? collect())
                                : collect();
                        @endphp

                        @if ($poolEvent)
                            <flux:card wire:key="pool-event-{{ $poolEvent->id }}" class="flex flex-col">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <flux:heading>{{ $event->name }}</flux:heading>
                                        @if ($event->question)
                                            <flux:text class="mt-1 text-sm">{{ $event->question }}</flux:text>
                                        @endif
                                    </div>
                                    <flux:badge :color="$predictionStatusColor">{{ $predictionStatusLabel }}</flux:badge>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500">
                                    <span>{{ $poolEvent->prediction_min_selections }} à {{ $poolEvent->prediction_max_selections }} choix</span>
                                    @if ($event->locks_at)
                                        <span>· Échéance {{ $event->locks_at->timezone($pool->timezone)->format('d/m H:i') }}</span>
                                    @endif
                                    <span>· {{ $effectiveStatus->label() }}</span>
                                </div>

                                <div class="mt-5 flex-1">
                                    @if ($poolEvent->prediction_max_selections === 1)
                                        <flux:radio.group wire:model.live="selections.{{ $poolEvent->id }}" :disabled="! $isOpen" label="Votre réponse" variant="cards" class="grid gap-2 sm:grid-cols-2">
                                            @foreach ($event->options as $option)
                                                <flux:radio :value="$option->id" :label="$option->label" />
                                            @endforeach
                                        </flux:radio.group>
                                    @else
                                        <flux:checkbox.group wire:model.live="selections.{{ $poolEvent->id }}" :disabled="! $isOpen" label="Vos réponses" class="grid gap-2 sm:grid-cols-2">
                                            @foreach ($event->options as $option)
                                                <flux:checkbox :value="$option->id" :label="$option->label" />
                                            @endforeach
                                        </flux:checkbox.group>
                                    @endif

                                    @error("selections.{$poolEvent->id}")
                                        <div class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</div>
                                    @enderror

                                    @if ($isRevealed)
                                        <div class="mt-5 space-y-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800/70">
                                            <div>
                                                <div class="font-semibold">Résultat officiel</div>
                                                <div class="mt-1 text-zinc-600 dark:text-zinc-300">
                                                    @if ($event->latestResult === null)
                                                        En attente de publication
                                                    @elseif ($event->latestResult->options->isEmpty())
                                                        Aucune sélection
                                                    @else
                                                        {{ $event->latestResult->options->pluck('label')->implode(' · ') }}
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($revealedPredictions->isNotEmpty())
                                                <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                                    <div class="font-semibold">Réponses révélées</div>
                                                    <div class="mt-2 space-y-1">
                                                        @foreach ($revealedPredictions as $revealedPrediction)
                                                            <div class="flex justify-between gap-3">
                                                                <span>{{ $revealedPrediction->poolMember->user->name }}</span>
                                                                <span class="text-end text-zinc-600 dark:text-zinc-300">{{ $revealedPrediction->options->pluck('label')->implode(' · ') }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="mt-5 flex items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                    <div class="text-xs text-zinc-500" wire:loading wire:target="selections.{{ $poolEvent->id }},submit({{ $poolEvent->id }})">Enregistrement…</div>
                                    <flux:button
                                        wire:click="submit({{ $poolEvent->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="submit({{ $poolEvent->id }})"
                                        :disabled="! $isOpen || $member === null"
                                        variant="primary"
                                        class="ms-auto"
                                    >
                                        {{ $prediction && in_array($prediction->status->value, ['submitted', 'locked'], true) ? 'Mettre à jour' : 'Soumettre' }}
                                    </flux:button>
                                </div>
                            </flux:card>
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <flux:card class="py-12 text-center">
                <flux:heading>Aucune prédiction disponible</flux:heading>
                <flux:text class="mt-2">Les événements officiels apparaîtront ici dès leur activation pour ce pool.</flux:text>
            </flux:card>
        @endforelse
    </div>
</section>
