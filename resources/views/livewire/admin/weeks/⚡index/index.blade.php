<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Weeks') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season (set one in Seasons).') }}</div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <flux:input wire:model="form.number" :label="__('Week #')" type="number" min="1" required />
                    <flux:input wire:model="form.name" :label="__('Name (optional)')" />

                    <div class="grid gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Phases') }}</div>
                            <flux:button type="button" size="sm" wire:click="addPhase">{{ __('Add Phase') }}</flux:button>
                        </div>

                        <div class="grid gap-3">
                            @foreach ($form['phases'] as $index => $phase)
                                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700" wire:key="admin-week-phase-{{ $index }}">
                                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ __('Phase') }} {{ $phase['position'] ?? ($index + 1) }} · {{ $this->phaseTypeLabel($phase['type'] ?? 'hoh') }}
                                        </div>
                                        <flux:button type="button" size="xs" variant="danger" wire:click="removePhase({{ $index }})">
                                            {{ __('Remove Phase') }}
                                        </flux:button>
                                    </div>

                                    <div class="grid gap-4 md:grid-cols-2">
                                        <flux:input wire:model="form.phases.{{ $index }}.position" :label="__('Order')" type="number" min="1" />
                                        <flux:select wire:model.live="form.phases.{{ $index }}.type" :label="__('Phase Type')">
                                            <option value="hoh">{{ __('Head of Household') }}</option>
                                            <option value="nominees">{{ __('Nominees') }}</option>
                                            <option value="veto">{{ __('Veto') }}</option>
                                            <option value="evictions">{{ __('Evictions') }}</option>
                                        </flux:select>
                                    </div>

                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        @if (($phase['type'] ?? null) === 'hoh')
                                            <flux:input wire:model="form.phases.{{ $index }}.hoh_count" :label="__('Head of Household Count')" type="number" min="0" max="20" />
                                        @endif

                                        @if (($phase['type'] ?? null) === 'nominees')
                                            <flux:input wire:model="form.phases.{{ $index }}.nominee_count" :label="__('Nominee Count')" type="number" min="0" max="20" />
                                        @endif

                                        @if (($phase['type'] ?? null) === 'veto')
                                            <flux:input wire:model="form.phases.{{ $index }}.winner_count" :label="__('Veto Winner Count')" type="number" min="0" max="20" />
                                            <flux:input wire:model="form.phases.{{ $index }}.saved_count" :label="__('Saved Count')" type="number" min="0" max="20" />
                                            <flux:input wire:model="form.phases.{{ $index }}.replacement_count" :label="__('Replacement Count')" type="number" min="0" max="20" />
                                        @endif

                                        @if (($phase['type'] ?? null) === 'evictions')
                                            <flux:input wire:model="form.phases.{{ $index }}.evicted_count" :label="__('Evicted Count')" type="number" min="0" max="20" />
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <flux:switch wire:model="form.is_locked" :label="__('Locked')" />
                    <flux:input wire:model="form.auto_lock_at" :label="__('Auto lock at (optional)')" type="datetime-local" />

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.starts_at" :label="__('Starts at (optional)')" type="datetime-local" />
                        <flux:input wire:model="form.ends_at" :label="__('Ends at (optional)')" type="datetime-local" />
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" :disabled="! $season">{{ __('Save') }}</flux:button>
                        <x-action-message on="week-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Week') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Outcome') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($weeks as $week)
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="grid gap-1">
                                            <span>{{ $week->name ?? __('Week').' '.$week->number }}</span>
                                            <div class="flex flex-wrap gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                @foreach ($week->phases as $phase)
                                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 dark:bg-zinc-800">
                                                        {{ __('Phase') }} {{ $phase->position }} · {{ $this->phaseTypeLabel($phase->type) }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <flux:button size="sm" :href="route('admin.weeks.outcome', $week)" wire:navigate.hover>
                                            {{ __('Set') }}
                                        </flux:button>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" type="button" wire:click="edit({{ $week->id }})">{{ __('Edit') }}</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
