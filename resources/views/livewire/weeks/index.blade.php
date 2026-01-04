<?php

use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    public function mount(): void
    {
        $this->season = Season::query()->where('is_active', true)->first();

        $this->weeks = Week::query()
            ->when(
                $this->season,
                fn (Builder $q) => $q->where('season_id', $this->season->id),
                fn (Builder $q) => $q->whereRaw('1=0'),
            )
            ->orderBy('number')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Weeks') }}</flux:heading>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
                @endif
            </div>

            <flux:button :href="route('current-week')" variant="primary" wire:navigate>
                {{ __('Current Week') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Week') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Deadline') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($weeks as $week)
                            <tr>
                                <td class="px-4 py-3">{{ $week->name ?? __('Week').' '.$week->number }}</td>
                                <td class="px-4 py-3">{{ $week->prediction_deadline_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    @if ($week->isLocked())
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked') }}</span>
                                    @else
                                        <span class="text-green-600">{{ __('Open') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button size="sm" :href="route('weeks.show', $week)" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400" colspan="4">
                                    {{ __('No weeks found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
