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
                <div class="grid gap-6">
                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('HOH') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->boss_count ?? 1); $i++)
                                <flux:select wire:model.live="form.boss_houseguest_ids.{{ $i }}" :label="($week->boss_count ?? 1) > 1 ? __('HOH (Boss) #').($i + 1) : __('HOH (Boss)')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Nominees') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->nominee_count ?? 2); $i++)
                                <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1).' ('.__('In danger').')'" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Veto') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" :disabled="$this->isLocked">
                                <option value="">—</option>
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <div class="hidden md:block md:col-span-2"></div>

                            <flux:select wire:model.live="form.veto_used" :label="__('Will the veto be used?')" :disabled="$this->isLocked">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>

                            @if (($form['veto_used'] ?? null) === '1')
                                <flux:select wire:model="form.saved_houseguest_id" :label="__('If used: Who will be saved?')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Evicted') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->evicted_count ?? 1); $i++)
                                <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>
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
