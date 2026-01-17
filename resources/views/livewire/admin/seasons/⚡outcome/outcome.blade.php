<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Season Outcome') }}</flux:heading>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
                @endif
            </div>
            <flux:button :href="route('admin.seasons.index')" wire:navigate>{{ __('Back to Seasons') }}</flux:button>
        </div>

        @if ($season)
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-6">
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:select wire:model.live="form.winner_houseguest_id" :label="__('Winner')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.first_evicted_houseguest_id" :label="__('First Evicted')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_1_houseguest_id" :label="__('Top 6 #1')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_2_houseguest_id" :label="__('Top 6 #2')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_3_houseguest_id" :label="__('Top 6 #3')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_4_houseguest_id" :label="__('Top 6 #4')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_5_houseguest_id" :label="__('Top 6 #5')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="form.top_6_6_houseguest_id" :label="__('Top 6 #6')" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit">{{ __('Save Outcome') }}</flux:button>
                        <x-action-message on="season-outcome-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>
        @endif
    </div>
</section>
