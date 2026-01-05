<?php

use App\Models\Houseguest;
use App\Models\Season;
use App\Models\SeasonPrediction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?SeasonPrediction $prediction = null;

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
        $this->season = Season::query()->where('is_active', true)->first();

        if (! $this->season) {
            $this->houseguests = collect();

            return;
        }

        $this->prediction = SeasonPrediction::query()
            ->where('season_id', $this->season->id)
            ->where('user_id', Auth::id())
            ->first();

        $selectedHouseguestIds = [];
        $isLocked = $this->prediction?->isConfirmed() ?? false;

        if ($this->prediction) {
            $top6 = $this->prediction->top_6_houseguest_ids ?? [];

            if ($isLocked) {
                $selectedHouseguestIds = array_values(array_unique(array_filter([
                    $this->prediction->winner_houseguest_id,
                    $this->prediction->first_evicted_houseguest_id,
                    ...$top6,
                ])));
            }

            $this->form = [
                'winner_houseguest_id' => $this->prediction->winner_houseguest_id,
                'first_evicted_houseguest_id' => $this->prediction->first_evicted_houseguest_id,
                'top_6_1_houseguest_id' => $top6[0] ?? null,
                'top_6_2_houseguest_id' => $top6[1] ?? null,
                'top_6_3_houseguest_id' => $top6[2] ?? null,
                'top_6_4_houseguest_id' => $top6[3] ?? null,
                'top_6_5_houseguest_id' => $top6[4] ?? null,
                'top_6_6_houseguest_id' => $top6[5] ?? null,
            ];
        }

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->when(
                $isLocked && $selectedHouseguestIds !== [],
                fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selectedHouseguestIds)),
                fn ($q) => $q->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getIsLockedProperty(): bool
    {
        return $this->prediction?->isConfirmed() ?? false;
    }

    public function save(): void
    {
        abort_if($this->season === null, 422);

        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $validated = $this->validate([
            'form.winner_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id'],
            'form.first_evicted_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.winner_houseguest_id'],

            'form.top_6_1_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.top_6_2_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id'],
            'form.top_6_3_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id'],
            'form.top_6_4_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id'],
            'form.top_6_5_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id'],
            'form.top_6_6_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id'],
        ]);

        $top6 = [
            $validated['form']['top_6_1_houseguest_id'],
            $validated['form']['top_6_2_houseguest_id'],
            $validated['form']['top_6_3_houseguest_id'],
            $validated['form']['top_6_4_houseguest_id'],
            $validated['form']['top_6_5_houseguest_id'],
            $validated['form']['top_6_6_houseguest_id'],
        ];

        $this->prediction = SeasonPrediction::query()->updateOrCreate(
            [
                'season_id' => $this->season->id,
                'user_id' => Auth::id(),
            ],
            [
                'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
                'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
                'top_6_houseguest_ids' => $top6,
            ],
        );

        $this->dispatch('season-prediction-saved');
    }

    public function confirm(): void
    {
        abort_if($this->season === null, 422);

        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $validated = $this->validate([
            'form.winner_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.first_evicted_houseguest_id'],
            'form.first_evicted_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.winner_houseguest_id'],

            'form.top_6_1_houseguest_id' => ['required', Rule::in($houseguestIds)],
            'form.top_6_2_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id'],
            'form.top_6_3_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id'],
            'form.top_6_4_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id'],
            'form.top_6_5_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id'],
            'form.top_6_6_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id'],
        ]);

        $top6 = [
            $validated['form']['top_6_1_houseguest_id'],
            $validated['form']['top_6_2_houseguest_id'],
            $validated['form']['top_6_3_houseguest_id'],
            $validated['form']['top_6_4_houseguest_id'],
            $validated['form']['top_6_5_houseguest_id'],
            $validated['form']['top_6_6_houseguest_id'],
        ];

        $prediction = SeasonPrediction::query()->updateOrCreate(
            [
                'season_id' => $this->season->id,
                'user_id' => Auth::id(),
            ],
            [
                'winner_houseguest_id' => $validated['form']['winner_houseguest_id'],
                'first_evicted_houseguest_id' => $validated['form']['first_evicted_houseguest_id'],
                'top_6_houseguest_ids' => $top6,
            ],
        );

        $prediction->confirm();
        $prediction->save();

        $this->prediction = $prediction;

        $this->dispatch('season-prediction-confirmed');
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Season Predictions') }}</flux:heading>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
                @endif
            </div>

            <flux:button :href="route('weeks.index')" wire:navigate>{{ __('Back to Weeks') }}</flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <div class="text-sm">
                    @if ($this->isLocked)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked (confirmed).') }}</span>
                    @else
                        <span class="text-green-600">{{ __('Open — you can edit until you confirm.') }}</span>
                    @endif
                </div>

                @if ($prediction?->confirmed_at)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Confirmed at:') }} {{ $prediction->confirmed_at->format('Y-m-d H:i') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-4">
                    <flux:heading size="lg" level="2">{{ __('Predictions') }}</flux:heading>

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:select wire:model="form.winner_houseguest_id" :label="__('Who will win?')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.first_evicted_houseguest_id" :label="__('Who will be the first evicted?')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="grid gap-4">
                    <flux:heading size="lg" level="2">{{ __('Top 6') }}</flux:heading>

                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:select wire:model="form.top_6_1_houseguest_id" :label="__('Top 6 #1')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_2_houseguest_id" :label="__('Top 6 #2')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_3_houseguest_id" :label="__('Top 6 #3')" :disabled="! $season || $this->isLocked">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_4_houseguest_id" :label="__('Top 6 #4')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_5_houseguest_id" :label="__('Top 6 #5')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.top_6_6_houseguest_id" :label="__('Top 6 #6')" :disabled="! $season || $this->isLocked" >
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" :disabled="! $season || $this->isLocked">{{ __('Save') }}</flux:button>
                    <flux:button variant="danger" type="button" wire:click="confirm" :disabled="! $season || $this->isLocked">{{ __('Confirm & Lock') }}</flux:button>
                    <x-action-message on="season-prediction-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    <x-action-message on="season-prediction-confirmed" class="text-sm">{{ __('Confirmed.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
