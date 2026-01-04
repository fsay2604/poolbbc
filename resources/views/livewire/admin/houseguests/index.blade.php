<?php

use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array{name:string,is_active:bool,sort_order:int} */
    public array $form = [
        'name' => '',
        'is_active' => true,
        'sort_order' => 0,
    ];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();
        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function edit(int $houseguestId): void
    {
        $houseguest = Houseguest::query()->findOrFail($houseguestId);

        $this->editingId = $houseguest->id;
        $this->form = [
            'name' => $houseguest->name,
            'is_active' => $houseguest->is_active,
            'sort_order' => $houseguest->sort_order,
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.is_active' => ['required', 'boolean'],
            'form.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $houseguest = $this->editingId
            ? Houseguest::query()->findOrFail($this->editingId)
            : new Houseguest(['season_id' => $this->season->id]);

        $houseguest->fill(array_merge($validated['form'], ['season_id' => $this->season->id]));
        $houseguest->save();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('houseguest-saved');
    }

    private function refresh(): void
    {
        $this->houseguests = Houseguest::query()
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Houseguests') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season (set one in Seasons).') }}</div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <flux:input wire:model="form.name" :label="__('Name')" required />
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.sort_order" :label="__('Sort order')" type="number" min="0" required />
                        <flux:switch wire:model="form.is_active" :label="__('Active')" />
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" :disabled="! $season">{{ __('Save') }}</flux:button>
                        <x-action-message on="houseguest-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
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
                            @foreach ($houseguests as $hg)
                                <tr>
                                    <td class="px-4 py-3">{{ $hg->name }}</td>
                                    <td class="px-4 py-3">
                                        @if ($hg->is_active)
                                            <span class="text-green-600">{{ __('Yes') }}</span>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" type="button" wire:click="edit({{ $hg->id }})">{{ __('Edit') }}</flux:button>
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
