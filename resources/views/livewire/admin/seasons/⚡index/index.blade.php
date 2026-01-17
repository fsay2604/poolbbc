<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl" level="1">{{ __('Seasons') }}</flux:heading>
            <flux:button variant="primary" type="button" wire:click="startCreate">{{ __('New Season') }}</flux:button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <flux:input wire:model="form.name" :label="__('Name')" required />

                    <flux:switch wire:model="form.is_active" :label="__('Active season')" />

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.starts_on" :label="__('Starts on')" type="date" />
                        <flux:input wire:model="form.ends_on" :label="__('Ends on')" type="date" />
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                        <x-action-message on="season-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Active') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Outcome') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($seasons as $season)
                                <tr>
                                    <td class="px-4 py-3">{{ $season->name }}</td>
                                    <td class="px-4 py-3">
                                        @if ($season->is_active)
                                            <span class="text-green-600">{{ __('Yes') }}</span>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($season->is_active)
                                            <flux:button size="sm" :href="route('admin.seasons.outcome')" wire:navigate>
                                                {{ __('Set') }}
                                            </flux:button>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">--</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-2">
                                            <flux:button size="sm" type="button" wire:click="edit({{ $season->id }})">{{ __('Edit') }}</flux:button>

                                            <flux:button size="sm" variant="danger" type="button" wire:click="confirmDelete({{ $season->id }})">
                                                {{ __('Delete') }}
                                            </flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <x-action-message on="season-deleted" class="text-sm">{{ __('Deleted.') }}</x-action-message>
    </div>

    <flux:modal wire:model.self="showConfirmSeasonDeletionModal" focusable class="max-w-lg">
        <form wire:submit="deleteSelectedSeason" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete season?') }}</flux:heading>

                <flux:subheading>
                    {{ __('This will permanently delete the season and all related data (weeks, houseguests, outcomes, predictions, and scores).') }}
                    @if ($confirmingSeasonDeletionName)
                        <div class="mt-2 font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $confirmingSeasonDeletionName }}
                        </div>
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" :disabled="$confirmingSeasonDeletionId === null">
                    {{ __('Delete season') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
