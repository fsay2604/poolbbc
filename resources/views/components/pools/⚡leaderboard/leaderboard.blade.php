
<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl" level="1">Classement · {{ $pool->name }}</flux:heading>
                <flux:text class="mt-1">Le registre détaillé des points est la source de vérité.</flux:text>
            </div>
            <flux:button :href="route('pools.show', $pool)" icon="arrow-left" wire:navigate.hover>Retour au pool</flux:button>
        </div>

        <flux:card class="overflow-hidden p-0">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Position</flux:table.column>
                    <flux:table.column>Membre</flux:table.column>
                    <flux:table.column align="end">Célébrités actives</flux:table.column>
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
                        <flux:badge color="zinc">{{ $row['total_points'] }} points</flux:badge>
                    </div>
                    <div class="mt-4 space-y-3">
                        @forelse ($row['member']->pointEntries as $entry)
                            <div class="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <div class="font-medium">{{ $entry->event->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $entry->reason }}</div>
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
