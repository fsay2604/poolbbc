<?php

use App\Models\Houseguest;
use App\Models\Prediction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public Prediction $prediction;

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
        'confirmed_at' => null,
    ];

    public function mount(Prediction $prediction): void
    {
        Gate::authorize('admin');

        $this->prediction = $prediction->loadMissing('user', 'week.season');

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->prediction->week->season_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $this->form = [
            'hoh_houseguest_id' => $this->prediction->hoh_houseguest_id,
            'nominee_1_houseguest_id' => $this->prediction->nominee_1_houseguest_id,
            'nominee_2_houseguest_id' => $this->prediction->nominee_2_houseguest_id,
            'veto_winner_houseguest_id' => $this->prediction->veto_winner_houseguest_id,
            'veto_used' => $this->prediction->veto_used,
            'saved_houseguest_id' => $this->prediction->saved_houseguest_id,
            'replacement_nominee_houseguest_id' => $this->prediction->replacement_nominee_houseguest_id,
            'evicted_houseguest_id' => $this->prediction->evicted_houseguest_id,
            'confirmed_at' => $this->prediction->confirmed_at?->format('Y-m-d\TH:i'),
        ];
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
            'form.confirmed_at' => ['nullable', 'date'],
        ]);

        if (($validated['form']['veto_used'] ?? null) !== true) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $this->prediction->fill($validated['form']);
        $this->prediction->last_admin_edited_by_user_id = Auth::id();
        $this->prediction->last_admin_edited_at = now();
        $this->prediction->admin_edit_count = (int) $this->prediction->admin_edit_count + 1;
        $this->prediction->save();

        $this->dispatch('prediction-admin-saved');
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Edit Prediction') }}</flux:heading>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $prediction->user->name ?? __('User') }} — {{ $prediction->week->name ?? __('Week').' '.$prediction->week->number }}
            </div>
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

                    <flux:input wire:model="form.confirmed_at" :label="__('Confirmed at (optional)')" type="datetime-local" />
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    <x-action-message on="prediction-admin-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                </div>

                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Admin edits:') }} {{ $prediction->admin_edit_count }}
                </div>
            </form>
        </div>
    </div>
</section>
