<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ $week->name ?? __('Week').' '.$week->number }}</flux:heading>
            </div>

            <flux:button :href="route('weeks.index')" wire:navigate.hover>
                {{ __('All Weeks') }}
            </flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <div class="text-sm">
                    @if ($this->isLocked)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked (confirmed or week locked).') }}</span>
                    @else
                        <span class="text-green-600">{{ __('Open — you can edit until you confirm or the week is locked.') }}</span>
                    @endif
                </div>

                @if ($prediction?->confirmed_at)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Confirmed at:') }} {{ $prediction->confirmed_at->format('Y-m-d H:i') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-4">
                    @foreach ($form['phases'] as $phaseIndex => $phase)
                        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" wire:key="week-phase-form-{{ $phase['phase_id'] }}">
                            <div class="mb-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ __('Phase') }} {{ $phase['position'] }} · {{ $this->phaseTypeLabel($phase['type']) }}
                            </div>

                            @if (($phase['type'] ?? null) === 'veto')
                                <div class="mb-3">
                                    <flux:switch
                                        wire:model.live="form.phases.{{ $phaseIndex }}.veto_used"
                                        :label="__('Veto used?')"
                                        :disabled="$this->isLocked"
                                    />
                                </div>
                            @endif

                            <div class="grid gap-4 md:grid-cols-2">
                                @foreach ($this->selectionLabels($phase['type']) as $listKey => $label)
                                    @php($values = is_array($phase[$listKey] ?? null) ? $phase[$listKey] : [])
                                    @php($isVetoDependentList = ($phase['type'] ?? null) === 'veto' && in_array($listKey, ['saved_ids', 'replacement_ids'], true))
                                    @php($showVetoDependentList = ! $isVetoDependentList || ($phase['veto_used'] ?? false))

                                    <div
                                        wire:key="week-phase-list-{{ $phase['phase_id'] }}-{{ $listKey }}"
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
                                                        :disabled="$this->isLocked"
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

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" :disabled="$this->isLocked">
                        {{ __('Save') }}
                    </flux:button>

                    <flux:button variant="danger" type="button" wire:click="confirm" :disabled="$this->isLocked">
                        {{ __('Confirm & Lock') }}
                    </flux:button>

                    <x-action-message on="prediction-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    <x-action-message on="prediction-confirmed" class="text-sm">{{ __('Confirmed.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
