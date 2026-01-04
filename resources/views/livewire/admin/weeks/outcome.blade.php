<?php

use App\Models\Houseguest;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?WeekOutcome $outcome = null;

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

    public function mount(Week $week): void
    {
        Gate::authorize('admin');

        $this->week = $week->loadMissing('season', 'outcome');
        $this->outcome = $this->week->outcome;

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->week->season_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($this->outcome) {
            $this->form = [
                'hoh_houseguest_id' => $this->outcome->hoh_houseguest_id,
                'nominee_1_houseguest_id' => $this->outcome->nominee_1_houseguest_id,
                'nominee_2_houseguest_id' => $this->outcome->nominee_2_houseguest_id,
                'veto_winner_houseguest_id' => $this->outcome->veto_winner_houseguest_id,
                'veto_used' => $this->outcome->veto_used,
                'saved_houseguest_id' => $this->outcome->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->outcome->replacement_nominee_houseguest_id,
                'evicted_houseguest_id' => $this->outcome->evicted_houseguest_id,
            ];
        }
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
        Gate::authorize('admin');

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

        $outcome = WeekOutcome::query()->updateOrCreate(
            ['week_id' => $this->week->id],
            array_merge(
                $validated['form'],
                [
                    'last_admin_edited_by_user_id' => Auth::id(),
                    'last_admin_edited_at' => now(),
                ],
            ),
        );

        $this->outcome = $outcome;
        $this->dispatch('outcome-saved');
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Outcome') }}</flux:heading>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $week->name ?? __('Week').' '.$week->number }}
                </div>
            </div>
            <flux:button :href="route('admin.weeks.index')" wire:navigate>{{ __('Back to Weeks') }}</flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <form wire:submit="save" class="grid gap-6">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model="form.hoh_houseguest_id" :label="__('HOH (Boss)')" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.evicted_houseguest_id" :label="__('Evicted')" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.nominee_1_houseguest_id" :label="__('Nominee #1')" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.nominee_2_houseguest_id" :label="__('Nominee #2')" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.veto_used" :label="__('Veto used?')" placeholder="—">
                        <option value="1">{{ __('Yes') }}</option>
                        <option value="0">{{ __('No') }}</option>
                    </flux:select>

                    <flux:select wire:model="form.saved_houseguest_id" :label="__('If used: Saved')" :disabled="$form['veto_used'] !== true" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')" :disabled="$form['veto_used'] !== true" placeholder="—">
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">{{ __('Save Outcome') }}</flux:button>
                    <x-action-message on="outcome-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
