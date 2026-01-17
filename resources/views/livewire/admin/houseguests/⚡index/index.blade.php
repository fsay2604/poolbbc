@php
    use App\Enums\Occupation;
@endphp

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Houseguests') }}</flux:heading>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season (set one in Seasons).') }}</div>
                @endif
            </div>
            <flux:button type="button" wire:click="startCreate">{{ __('New Houseguest') }}</flux:button>
        </div>

        <div class="grid gap-6">
            <div class="w-full md:w-2/3 rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="grid flex-1 gap-4 md:grid-cols-2">
                            <flux:input wire:model="form.name" :label="__('Name')" required />

                            <flux:select wire:model="form.sex" :label="__('Sex')">
                                <option value="M">{{ __('Male') }}</option>
                                <option value="F">{{ __('Female') }}</option>
                            </flux:select>
                        </div>

                        <flux:switch wire:model="form.is_active" :label="__('Active')" align="left" class="self-end" />
                    </div>

                    <flux:pillbox wire:model="form.occupations" :label="__('Occupations')" placeholder="{{ __('Select occupations') }}" multiple searchable>
                        @foreach (Occupation::cases() as $occupation)
                            <flux:pillbox.option value="{{ $occupation->value }}">{{ __($occupation->value) }}</flux:pillbox.option>
                        @endforeach

                    </flux:pillbox>

                    <flux:file-upload wire:model="avatar">
                        <div class="grid items-center gap-4">
                            @if ($avatar)
                                <img src="{{ $avatar?->temporaryUrl() }}" class="size-10 rounded-full object-cover" />
                            @elseif ($form['avatar_url'])
                                <img src="{{ asset('storage/'.$form['avatar_url']) }}" class="size-10 rounded-full object-cover" />
                            @else
                                <flux:avatar :name="$form['name']" size="sm" circle />
                            @endif

                            <div class="grid gap-1">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Avatar') }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Upload an image (max 2MB).') }}</div>
                            </div>
                        </div>
                    </flux:file-upload>

                    <flux:input wire:model="form.sort_order" :label="__('Sort order')" type="number" min="0" required />

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" :disabled="! $season">{{ __('Save') }}</flux:button>
                        <x-action-message on="houseguest-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>

            @if ($houseguests->isEmpty())
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No houseguests yet.') }}</div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                    @foreach ($houseguests as $hg)
                        @php
                            $isEditing = $editingId === $hg->id;
                            $sexLabel = $hg->sex === 'F' ? __('Female') : ($hg->sex === 'M' ? __('Male') : '--');
                            $occupations = is_array($hg->occupations) ? array_values($hg->occupations) : [];
                            $occupationLabels = $occupations !== []
                                ? implode(', ', array_map(fn ($occupation) => __($occupation), $occupations))
                                : '--';
                        @endphp

                        <flux:card
                            wire:key="houseguest-{{ $hg->id }}"
                            class="w-full cursor-pointer gap-4 transition hover:border-emerald-400/60 hover:shadow-sm {{ $isEditing ? 'ring-2 ring-emerald-500/60' : '' }}"
                            role="button"
                            tabindex="0"
                            wire:click="edit({{ $hg->id }})"
                        >
                            <div class="flex w-full flex-col gap-4">
                                <div class="flex justify-center">
                                    <div @class([$hg->is_active ? '' : 'opacity-70'])>
                                        <flux:avatar
                                            :src="$hg->avatar_url ? asset('storage/'.$hg->avatar_url) : null"
                                            :name="$hg->name"
                                            class="size-16 object-cover"
                                            circle
                                        />
                                    </div>
                                </div>

                                 <flux:separator />


                                <div class="flex w-full items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <flux:heading size="sm" level="3" class="truncate">
                                            {{ \Illuminate\Support\Str::title($hg->name) }}
                                        </flux:heading>
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('Sort order') }}: {{ $hg->sort_order }}
                                        </flux:text>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        @if ($hg->is_active)
                                            <flux:badge color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge color="red">{{ __('Inactive') }}</flux:badge>
                                        @endif

                                        <flux:button
                                            size="sm"
                                            variant="filled"
                                            type="button"
                                            class="text-red-600 hover:text-red-700"
                                            aria-label="{{ __('Delete') }}"
                                            wire:click.stop="confirmDelete({{ $hg->id }})"
                                        >
                                            <flux:icon.trash class="size-4" />
                                        </flux:button>
                                    </div>
                                </div>

                                <div class="flex w-full items-start justify-between gap-6 text-xs">
                                    <div class="grid gap-1 text-zinc-500 dark:text-zinc-400">
                                        <span>{{ __('Sex') }}</span>
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $sexLabel }}</span>
                                    </div>
                                    <div class="grid gap-1 text-right text-zinc-500 dark:text-zinc-400">
                                        <span>{{ __('Occupations') }}</span>
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $occupationLabels }}</span>
                                    </div>
                                </div>
                            </div>

                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- <x-action-message on="houseguest-deleted" class="text-sm">{{ __('Deleted.') }}</x-action-message> --}}
    </div>

    <flux:modal wire:model.self="showConfirmHouseguestDeletionModal" focusable class="max-w-lg">
        <form wire:submit="deleteSelectedHouseguest" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete houseguest?') }}</flux:heading>

                <flux:subheading>
                    {{ __('This will permanently delete the houseguest and any related data for the season.') }}
                    @if ($confirmingHouseguestDeletionName)
                        <div class="mt-2 font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $confirmingHouseguestDeletionName }}
                        </div>
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" :disabled="$confirmingHouseguestDeletionId === null">
                    {{ __('Delete houseguest') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
