<?php

use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    /** @var array<string, mixed> */
    public array $form = [
        'number' => 1,
        'boss_count' => 1,
        'nominee_count' => 2,
        'evicted_count' => 1,
        'name' => null,
        'is_locked' => true,
        'auto_lock_at' => null,
        'starts_at' => null,
        'ends_at' => null,
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
        $nextNumber = (int) (Week::query()->when($this->season, fn ($q) => $q->where('season_id', $this->season->id))->max('number') ?? 0) + 1;
        $this->editingId = null;
        $this->form = [
            'number' => $nextNumber,
            'boss_count' => 1,
            'nominee_count' => 2,
            'evicted_count' => 1,
            'name' => null,
            'is_locked' => true,
            'auto_lock_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function edit(int $weekId): void
    {
        $week = Week::query()->findOrFail($weekId);
        $this->editingId = $week->id;
        $this->form = [
            'number' => $week->number,
            'boss_count' => $week->boss_count ?? 1,
            'nominee_count' => $week->nominee_count ?? 2,
            'evicted_count' => $week->evicted_count ?? 1,
            'name' => $week->name,
            'is_locked' => $week->is_locked,
            'auto_lock_at' => $week->auto_lock_at?->format('Y-m-d\TH:i'),
            'starts_at' => $week->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $week->ends_at?->format('Y-m-d\TH:i'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $validated = $this->validate([
            'form.number' => ['required', 'integer', 'min:1'],
            'form.boss_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.nominee_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.evicted_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.is_locked' => ['required', 'boolean'],
            'form.auto_lock_at' => ['nullable', 'date'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date'],
        ]);

        $week = $this->editingId ? Week::query()->findOrFail($this->editingId) : new Week(['season_id' => $this->season->id]);

        $week->fill(array_merge($validated['form'], ['season_id' => $this->season->id]));
        $week->save();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('week-saved');
    }

    private function refresh(): void
    {
        $this->weeks = Week::query()
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('number')
            ->get();

        if ($this->editingId === null) {
            $this->startCreate();
        }
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Weeks') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season (set one in Seasons).') }}</div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <flux:input wire:model="form.number" :label="__('Week #')" type="number" min="1" required />

                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:input wire:model="form.boss_count" :label="__('Bosses')" type="number" min="1" max="20" required />
                        <flux:input wire:model="form.nominee_count" :label="__('Nominees')" type="number" min="1" max="20" required />
                        <flux:input wire:model="form.evicted_count" :label="__('Evicted')" type="number" min="1" max="20" required />
                    </div>
                    <flux:input wire:model="form.name" :label="__('Name (optional)')" />

                    <flux:switch wire:model="form.is_locked" :label="__('Locked')" />
                    <flux:input wire:model="form.auto_lock_at" :label="__('Auto lock at (optional)')" type="datetime-local" />

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.starts_at" :label="__('Starts at (optional)')" type="datetime-local" />
                        <flux:input wire:model="form.ends_at" :label="__('Ends at (optional)')" type="datetime-local" />
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" :disabled="! $season">{{ __('Save') }}</flux:button>
                        <x-action-message on="week-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Week') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Outcome') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($weeks as $week)
                                <tr>
                                    <td class="px-4 py-3">{{ $week->name ?? __('Week').' '.$week->number }}</td>
                                    <td class="px-4 py-3">
                                        <flux:button size="sm" :href="route('admin.weeks.outcome', $week)" wire:navigate>
                                            {{ __('Set') }}
                                        </flux:button>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" type="button" wire:click="edit({{ $week->id }})">{{ __('Edit') }}</flux:button>
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
