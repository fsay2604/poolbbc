@php($rows = $this->rows)

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Classement · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Le registre détaillé des points est la source de vérité.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <x-pools.navigation :pool="$pool" :available-pools="$availablePools" />

        <flux:card>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="selectedRound" label="Filtrer par ronde">
                    <flux:select.option value="">Toutes les rondes</flux:select.option>
                    @foreach ($roundOptions as $roundOption)
                        <flux:select.option :value="$roundOption['value']">{{ $roundOption['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="selectedEventTypeId" label="Filtrer par type d’événement">
                    <flux:select.option value="">Tous les types</flux:select.option>
                    @foreach ($eventTypes as $eventType)
                        <flux:select.option :value="$eventType->id">{{ $eventType->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card class="overflow-hidden p-0">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Position</flux:table.column>
                    <flux:table.column>Membre</flux:table.column>
                    <flux:table.column align="end">Célébrités actives</flux:table.column>
                    <flux:table.column align="end">Variation</flux:table.column>
                    <flux:table.column align="end">Points</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['member']->id">
                            <flux:table.cell variant="strong">{{ $row['rank'] }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :initials="$row['member']->user->initials()" />
                                    <div>
                                        <div class="font-medium">{{ $row['member']->user->name }}</div>
                                        @if ($row['member']->user_id === auth()->id())
                                            <div class="text-xs text-accent">Vous</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['active_houseguests'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                @if ($row['rank_change'] === null)
                                    <span class="text-zinc-500" aria-label="Variation indisponible pour une vue filtrée">—</span>
                                @elseif ($row['rank_change'] > 0)
                                    <span class="text-emerald-600" aria-label="Monte de {{ $row['rank_change'] }} position">↑ {{ $row['rank_change'] }}</span>
                                @elseif ($row['rank_change'] < 0)
                                    <span class="text-red-600" aria-label="Descend de {{ abs($row['rank_change']) }} position">↓ {{ abs($row['rank_change']) }}</span>
                                @else
                                    <span class="text-zinc-500" aria-label="Position inchangée">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" variant="strong" class="text-lg tabular-nums">{{ $row['total_points'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            @if ($rows->isEmpty())
                <div class="p-10 text-center text-sm text-zinc-500">Aucun membre à classer.</div>
            @endif
        </flux:card>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($rows as $row)
                <flux:card wire:key="points-{{ $row['member']->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading>{{ $row['member']->user->name }}</flux:heading>
                        <div class="flex items-center gap-2">
                            <flux:badge color="zinc">{{ $row['member']->pointEntries->sum('points') }} points détaillés</flux:badge>
                            <flux:badge color="blue">{{ $row['total_points'] }} total</flux:badge>
                        </div>
                    </div>
                    <div class="mt-4 space-y-3">
                        @forelse ($row['member']->pointEntries as $entry)
                            <div class="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <div class="font-medium">{{ $entry->event?->name ?? $entry->poolEvent?->seasonEvent?->name ?? 'Ajustement' }}</div>
                                    <div class="text-xs text-zinc-500">{{ $entry->reason }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if ($entry->reverses_point_entry_id)
                                            <flux:badge size="sm" color="amber">Correction inverse</flux:badge>
                                        @endif
                                        @if ($entry->event?->round || $entry->poolEvent?->seasonEvent?->round)
                                            <flux:badge size="sm" color="zinc">{{ $entry->event?->round?->name ?? $entry->poolEvent?->seasonEvent?->round?->name }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                                <div class="font-semibold tabular-nums {{ $entry->points < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $entry->points > 0 ? '+' : '' }}{{ $entry->points }}</div>
                            </div>
                        @empty
                            <flux:text class="text-sm text-zinc-500">Aucun point publié.</flux:text>
                        @endforelse
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
</section>
