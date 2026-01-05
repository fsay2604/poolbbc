<?php

use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array<string, mixed> */
    public array $form = [
        'winner_houseguest_id' => null,
        'first_evicted_houseguest_id' => null,
        'top_6_1_houseguest_id' => null,
        'top_6_2_houseguest_id' => null,
        'top_6_3_houseguest_id' => null,
        'top_6_4_houseguest_id' => null,
        'top_6_5_houseguest_id' => null,
        'top_6_6_houseguest_id' => null,
    ];

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();

        if (! $this->season) {
            $this->houseguests = collect();

            return;
        }

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $top6 = is_array($this->season->top_6_houseguest_ids) ? array_values($this->season->top_6_houseguest_ids) : [];

        $this->form = [
            'winner_houseguest_id' => $this->season->winner_houseguest_id,
            'first_evicted_houseguest_id' => $this->season->first_evicted_houseguest_id,
            'top_6_1_houseguest_id' => $top6[0] ?? null,
            'top_6_2_houseguest_id' => $top6[1] ?? null,
            'top_6_3_houseguest_id' => $top6[2] ?? null,
            'top_6_4_houseguest_id' => $top6[3] ?? null,
            'top_6_5_houseguest_id' => $top6[4] ?? null,
            'top_6_6_houseguest_id' => $top6[5] ?? null,
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        abort_if($this->season === null, 404);

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $validated = $this->validate([
            'form.winner_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id'],
            'form.first_evicted_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.winner_houseguest_id'],

            'form.top_6_1_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id', 'different:form.top_6_6_houseguest_id'],
            'form.top_6_2_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_1_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id', 'different:form.top_6_6_houseguest_id'],
            'form.top_6_3_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id', 'different:form.top_6_6_houseguest_id'],
            'form.top_6_4_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_5_houseguest_id', 'different:form.top_6_6_houseguest_id'],
            'form.top_6_5_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_6_houseguest_id'],
            'form.top_6_6_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id', 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id'],
        ]);

        $this->season->forceFill([
            'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
            'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
            'top_6_houseguest_ids' => [
                $validated['form']['top_6_1_houseguest_id'],
                $validated['form']['top_6_2_houseguest_id'],
                $validated['form']['top_6_3_houseguest_id'],
                $validated['form']['top_6_4_houseguest_id'],
                $validated['form']['top_6_5_houseguest_id'],
                $validated['form']['top_6_6_houseguest_id'],
            ],
        ])->save();

        $this->dispatch('season-outcome-saved');
    }

    public function recalculate(ScoreSeasonPredictions $scoreSeasonPredictions): void
    {
        Gate::authorize('admin');

        abort_if($this->season === null, 404);

        $scoreSeasonPredictions->run($this->season);

        $this->dispatch('season-scores-recalculated');
    }
}; ?>

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
                        <flux:button type="button" wire:click="recalculate">{{ __('Recalculate Season Scores') }}</flux:button>
                        <x-action-message on="season-outcome-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                        <x-action-message on="season-scores-recalculated" class="text-sm">{{ __('Recalculated.') }}</x-action-message>
                    </div>
                </form>
            </div>
        @endif
    </div>
</section>
