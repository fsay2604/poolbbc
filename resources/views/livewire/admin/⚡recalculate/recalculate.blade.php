<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <flux:heading size="xl" level="1">{{ __('Recalculate Scores') }}</flux:heading>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="grid gap-4">
                <flux:select wire:model.live="weekId" :label="__('Week (optional)')">
                    <option value="">{{ __('All weeks') }}</option>
                    @foreach ($weeks as $week)
                        <option value="{{ $week->id }}">{{ $week->name ?? __('Week').' '.$week->number }}</option>
                    @endforeach
                </flux:select>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="button" wire:click="recalculate">{{ __('Recalculate') }}</flux:button>
                    <x-action-message on="scores-recalculated" class="text-sm">{{ __('Done.') }}</x-action-message>
                </div>
            </div>
        </div>
    </div>
</section>