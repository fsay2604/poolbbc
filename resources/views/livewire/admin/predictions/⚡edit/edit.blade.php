<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Edit Prediction') }}</flux:heading>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $prediction->user->name ?? __('User') }} — {{ $prediction->week->name ?? __('Week').' '.$prediction->week->number }}
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-6">
                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('HOH') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($prediction->week->boss_count ?? 1); $i++)
                                <flux:select wire:model.live="form.boss_houseguest_ids.{{ $i }}" :label="($prediction->week->boss_count ?? 1) > 1 ? __('HOH (Boss) #').($i + 1) : __('HOH (Boss)')" placeholder="—">
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
                            @for ($i = 0; $i < ($prediction->week->nominee_count ?? 2); $i++)
                                <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1)" placeholder="—">
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
                            <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" placeholder="—">
                                <option value="">—</option>
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <div class="hidden md:block md:col-span-2"></div>

                            <flux:select wire:model.live="form.veto_used" :label="__('Veto used?')" placeholder="—">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>

                            <flux:select wire:model.live="form.saved_houseguest_id" :label="__('If used: Saved')" :disabled="! $form['veto_used']" placeholder="—">
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')" :disabled="! $form['veto_used']" placeholder="—">
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Evicted') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($prediction->week->evicted_count ?? 1); $i++)
                                <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($prediction->week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')" placeholder="—">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

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