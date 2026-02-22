<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Edit Prediction') }}</flux:heading>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $prediction->user->name ?? __('User') }} - {{ $prediction->week->name ?? __('Week').' '.$prediction->week->number }}
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-4">
                    @foreach ($form['phases'] as $phaseIndex => $phase)
                        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" wire:key="admin-edit-phase-{{ $phase['phase_id'] }}">
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
                                        wire:key="admin-edit-phase-list-{{ $phase['phase_id'] }}-{{ $listKey }}"
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
                                                        placeholder="-"
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

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Confirmation') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <flux:input wire:model="form.confirmed_at" :label="__('Confirmed at (optional)')" type="datetime-local" />
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    <x-action-message on="prediction-admin-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                </div>

                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Admin edits:') }} {{ $prediction->admin_edit_count }}
                </div>
            </form>
        </div>
    </div>
</section>
