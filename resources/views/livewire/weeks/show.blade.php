<?php

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    /** @var array<string, mixed> */
    public array $form = [
        'hoh_houseguest_id' => null,
        'nominee_1_houseguest_id' => null,
        'nominee_2_houseguest_id' => null,
        'veto_winner_houseguest_id' => null,
        'veto_used' => null,
        'saved_houseguest_id' => null,
        'replacement_nominee_houseguest_id' => null,
        'evicted_houseguest_id' => null,
    ];

    public ?Prediction $prediction = null;

    public function mount(Week $week): void
    {
        $this->week = $week->loadMissing('season');

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->week->season_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $this->prediction = Prediction::query()
            ->where('week_id', $this->week->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($this->prediction) {
            $this->form = [
                'hoh_houseguest_id' => $this->prediction->hoh_houseguest_id,
                'nominee_1_houseguest_id' => $this->prediction->nominee_1_houseguest_id,
                'nominee_2_houseguest_id' => $this->prediction->nominee_2_houseguest_id,
                'veto_winner_houseguest_id' => $this->prediction->veto_winner_houseguest_id,
                'veto_used' => $this->prediction->veto_used,
                'saved_houseguest_id' => $this->prediction->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->prediction->replacement_nominee_houseguest_id,
                'evicted_houseguest_id' => $this->prediction->evicted_houseguest_id,
            ];
        }
    }

    public function getIsLockedProperty(): bool
    {
        $confirmed = $this->prediction?->isConfirmed() ?? false;

        return $confirmed || $this->week->isLocked();
    }

    public function updatedFormVetoUsed(mixed $value): void
    {
        $this->form['veto_used'] = $this->normalizeNullableBoolean($value);

        if ($this->form['veto_used'] !== true) {
            $this->form['saved_houseguest_id'] = null;
            $this->form['replacement_nominee_houseguest_id'] = null;
        }
    }

    private function normalizeNullableBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }

    public function save(): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $validated = $this->validate([
            'form.hoh_houseguest_id' => [
                'nullable',
                Rule::in($houseguestIds),
                'different:form.nominee_1_houseguest_id',
                'different:form.nominee_2_houseguest_id',
                'different:form.veto_winner_houseguest_id',
                'different:form.evicted_houseguest_id',
            ],
            'form.nominee_1_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.nominee_2_houseguest_id', 'different:form.hoh_houseguest_id'],
            'form.nominee_2_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.nominee_1_houseguest_id', 'different:form.hoh_houseguest_id'],
            'form.veto_winner_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.hoh_houseguest_id'],
            'form.veto_used' => ['nullable', 'boolean'],
            'form.saved_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.evicted_houseguest_id' => ['nullable', Rule::in($houseguestIds), 'different:form.hoh_houseguest_id'],
        ]);

        if (($validated['form']['veto_used'] ?? null) !== true) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $this->prediction = Prediction::query()->updateOrCreate(
            [
                'week_id' => $this->week->id,
                'user_id' => Auth::id(),
            ],
            $validated['form'],
        );

        $this->dispatch('prediction-saved');
    }

    public function confirm(): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $validated = $this->validate([
            'form.hoh_houseguest_id' => [
                'required',
                Rule::in($houseguestIds),
                'different:form.nominee_1_houseguest_id',
                'different:form.nominee_2_houseguest_id',
                'different:form.veto_winner_houseguest_id',
                'different:form.evicted_houseguest_id',
            ],
            'form.nominee_1_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.nominee_2_houseguest_id', 'different:form.hoh_houseguest_id'],
            'form.nominee_2_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.nominee_1_houseguest_id', 'different:form.hoh_houseguest_id'],
            'form.veto_winner_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.hoh_houseguest_id'],
            'form.veto_used' => ['required', 'boolean'],
            'form.saved_houseguest_id' => ['required_if:form.veto_used,1', Rule::in($houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['required_if:form.veto_used,1', Rule::in($houseguestIds)],
            'form.evicted_houseguest_id' => ['required', Rule::in($houseguestIds), 'different:form.hoh_houseguest_id'],
        ]);

        if (($validated['form']['veto_used'] ?? null) !== true) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $prediction = Prediction::query()->updateOrCreate(
            [
                'week_id' => $this->week->id,
                'user_id' => Auth::id(),
            ],
            $validated['form'],
        );

        $prediction->confirm();
        $prediction->save();

        $this->prediction = $prediction;

        $this->dispatch('prediction-confirmed');
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ $week->name ?? __('Week').' '.$week->number }}</flux:heading>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Deadline:') }} {{ $week->prediction_deadline_at->format('Y-m-d H:i') }}
                </div>
            </div>

            <flux:button :href="route('weeks.index')" wire:navigate>
                {{ __('All Weeks') }}
            </flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <div class="text-sm">
                    @if ($this->isLocked)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked (confirmed or deadline passed).') }}</span>
                    @else
                        <span class="text-green-600">{{ __('Open — you can edit until you confirm and before the deadline.') }}</span>
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
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model="form.hoh_houseguest_id" :label="__('HOH (Boss)')" :disabled="$this->isLocked" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.evicted_houseguest_id" :label="__('Evicted')" :disabled="$this->isLocked" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.nominee_1_houseguest_id" :label="__('Nominee #1 (In danger)')" :disabled="$this->isLocked" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.nominee_2_houseguest_id" :label="__('Nominee #2 (In danger)')" :disabled="$this->isLocked" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" :disabled="$this->isLocked" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.veto_used" :label="__('Will the veto be used?')" :disabled="$this->isLocked" placeholder="—">
                        <option value="1">{{ __('Yes') }}</option>
                        <option value="0">{{ __('No') }}</option>
                    </flux:select>

                    <flux:select
                        wire:model="form.saved_houseguest_id"
                        :label="__('If used: Who will be saved?')"
                        :disabled="$this->isLocked || $form['veto_used'] !== true"
                        placeholder="—"
                    >
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        wire:model="form.replacement_nominee_houseguest_id"
                        :label="__('If used: Replacement nominee')"
                        :disabled="$this->isLocked || $form['veto_used'] !== true"
                        placeholder="—"
                    >
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" :disabled="$this->isLocked">
                        {{ __('Save') }}
                    </flux:button>

                    <flux:button variant="danger" type="button" wire:click="confirm" :disabled="$this->isLocked">
                        {{ __('Confirm & Lock') }}
                    </flux:button>

                    <x-action-message on="prediction-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    <x-action-message on="prediction-confirmed" class="text-sm">{{ __('Confirmed.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
