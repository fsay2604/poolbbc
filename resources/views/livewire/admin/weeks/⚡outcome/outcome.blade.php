<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Outcome') }}</flux:heading>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $week->name ?? __('Week').' '.$week->number }}
                </div>
            </div>
            <flux:button :href="route('admin.weeks.index')" wire:navigate.hover>{{ __('Back to Weeks') }}</flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-4">
                    @foreach ($form['phases'] as $phaseIndex => $phase)
                        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" wire:key="admin-outcome-phase-{{ $phase['phase_id'] }}">
                            <div class="mb-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ __('Phase') }} {{ $phase['position'] }} · {{ $this->phaseTypeLabel($phase['type']) }}
                            </div>

                            @if (($phase['type'] ?? null) === 'veto')
                                <div class="mb-3">
                                    <flux:switch
                                        wire:model.live="form.phases.{{ $phaseIndex }}.veto_used"
                                        :label="__('Veto used?')"
                                    />
                                </div>
                            @endif

                            <div class="grid gap-4 md:grid-cols-2">
                                @foreach ($this->selectionLabels($phase['type']) as $listKey => $label)
                                    @php($values = is_array($phase[$listKey] ?? null) ? $phase[$listKey] : [])
                                    @php($isVetoDependentList = ($phase['type'] ?? null) === 'veto' && in_array($listKey, ['saved_ids', 'replacement_ids'], true))
                                    @php($showVetoDependentList = ! $isVetoDependentList || ($phase['veto_used'] ?? false))

                                    <div
                                        wire:key="admin-outcome-phase-list-{{ $phase['phase_id'] }}-{{ $listKey }}"
                                        @class(['hidden' => ! $showVetoDependentList])
                                    >
                                        @if ($values === [])
                                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}: {{ __('None') }}</div>
                                        @else
                                            <div class="grid gap-4">
                                                @foreach ($values as $listIndex => $value)
                                                    <flux:select
                                                        wire:model.live="form.phases.{{ $phaseIndex }}.{{ $listKey }}.{{ $listIndex }}"
                                                        :label="count($values) > 1 ? $label.' #'.($listIndex + 1) : $label"
                                                    >
                                                        <option value="">-</option>
                                                        @foreach ($houseguests as $hg)
                                                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($outcome)
                    <flux:textarea wire:model="correctionReason" :label="__('Correction reason')" rows="2" required />
                @endif

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">{{ __('Save Outcome') }}</flux:button>
                    <x-action-message on="outcome-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
