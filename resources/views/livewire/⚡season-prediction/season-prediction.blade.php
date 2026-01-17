<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Season Predictions') }}</flux:heading>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
                @endif
            </div>

            <flux:button :href="route('weeks.index')" wire:navigate.hover>{{ __('Back to Weeks') }}</flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <div class="text-sm">
                    @if ($this->isLocked)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked (confirmed).') }}</span>
                    @else
                        <span class="text-green-600">{{ __('Open — you can edit until you confirm.') }}</span>
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
                    <flux:heading size="lg" level="2">{{ __('Predictions') }}</flux:heading>

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:select wire:model="form.winner_houseguest_id" :label="__('Who will win?')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.first_evicted_houseguest_id" :label="__('Who will be the first evicted?')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="grid gap-4">
                    <flux:heading size="lg" level="2">{{ __('Top 6') }}</flux:heading>

                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:select wire:model="form.top_6_1_houseguest_id" :label="__('Top 6 #1')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_2_houseguest_id" :label="__('Top 6 #2')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_3_houseguest_id" :label="__('Top 6 #3')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_4_houseguest_id" :label="__('Top 6 #4')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_5_houseguest_id" :label="__('Top 6 #5')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_6_houseguest_id" :label="__('Top 6 #6')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" :disabled="! $season || $this->isLocked">{{ __('Save') }}</flux:button>
                    <flux:button variant="danger" type="button" wire:click="confirm" :disabled="! $season || $this->isLocked">{{ __('Confirm & Lock') }}</flux:button>
                    <x-action-message on="season-prediction-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    <x-action-message on="season-prediction-confirmed" class="text-sm">{{ __('Confirmed.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
