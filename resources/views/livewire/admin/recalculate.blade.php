<?php

use App\Actions\Predictions\ScoreWeek;
use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component {
    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    public ?int $weekId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $season = Season::query()->where('is_active', true)->first();

        $this->weeks = Week::query()
            ->when($season, fn ($q) => $q->where('season_id', $season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('number')
            ->get();
    }

    public function recalculate(ScoreWeek $scoreWeek, ScoreSeasonPredictions $scoreSeasonPredictions): void
    {
        Gate::authorize('admin');

        $admin = Auth::user();
        abort_if($admin === null, 403);

        $weeks = $this->weekId
            ? Week::query()->whereKey($this->weekId)->get()
            : $this->weeks;

        foreach ($weeks as $week) {
            $scoreWeek->run($week, $admin);
        }

        $season = Season::query()->where('is_active', true)->first();
        if ($season) {
            $scoreSeasonPredictions->run($season, $admin);
        }

        $this->dispatch('scores-recalculated');
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <flux:heading size="xl" level="1">{{ __('Recalculate Scores') }}</flux:heading>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="grid gap-4">
                <flux:select wire:model="weekId" :label="__('Week (optional)')" placeholder="{{ __('All weeks') }}">
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
