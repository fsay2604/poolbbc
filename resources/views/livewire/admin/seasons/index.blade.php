<?php

use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    /** @var \Illuminate\Support\Collection<int, \App\Models\Season> */
    public $seasons;

    /** @var array{name:string,is_active:bool,starts_on:?string,ends_on:?string} */
    public array $form = [
        'name' => '',
        'is_active' => false,
        'starts_on' => null,
        'ends_on' => null,
    ];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'is_active' => false,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    public function edit(int $seasonId): void
    {
        $season = Season::query()->findOrFail($seasonId);

        $this->editingId = $season->id;
        $this->form = [
            'name' => $season->name,
            'is_active' => $season->is_active,
            'starts_on' => $season->starts_on?->format('Y-m-d'),
            'ends_on' => $season->ends_on?->format('Y-m-d'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.is_active' => ['required', 'boolean'],
            'form.starts_on' => ['nullable', 'date'],
            'form.ends_on' => ['nullable', 'date'],
        ]);

        $season = $this->editingId
            ? Season::query()->findOrFail($this->editingId)
            : new Season();

        $season->fill($validated['form']);

        if (($validated['form']['is_active'] ?? false) === true) {
            Season::query()->where('id', '!=', $season->id)->update(['is_active' => false]);
        }

        $season->save();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('season-saved');
    }

    private function refresh(): void
    {
        $this->seasons = Season::query()->orderByDesc('is_active')->orderByDesc('id')->get();
    }
}; ?>

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
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" type="button" wire:click="edit({{ $season->id }})">{{ __('Edit') }}</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
