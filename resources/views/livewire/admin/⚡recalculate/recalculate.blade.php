<div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="grid gap-1">
            <flux:heading size="lg" level="2">{{ __('Recalculate Scores') }}</flux:heading>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Updates all weeks and season scores.') }}
            </div>
        </div>
        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="button" wire:click="recalculate">
                {{ __('Recalculate All Weeks') }}
            </flux:button>
            <x-action-message on="scores-recalculated" class="text-sm">{{ __('Done.') }}</x-action-message>
        </div>
    </div>
</div>
