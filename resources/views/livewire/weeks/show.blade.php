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
        'nominee_houseguest_ids' => [],
        'veto_winner_houseguest_id' => null,
        'veto_used' => null,
        'saved_houseguest_id' => null,
        'replacement_nominee_houseguest_id' => null,
        'evicted_houseguest_ids' => [],
    ];

    public ?Prediction $prediction = null;

    public function mount(Week $week): void
    {
        $this->week = $week->loadMissing('season');

        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

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
            $nominees = $this->normalizeIdList($this->prediction->nominee_houseguest_ids);
            if (count($nominees) === 0) {
                $nominees = $this->normalizeIdList([
                    $this->prediction->nominee_1_houseguest_id,
                    $this->prediction->nominee_2_houseguest_id,
                ]);
            }

            $evicted = $this->normalizeIdList($this->prediction->evicted_houseguest_ids);
            if (count($evicted) === 0) {
                $evicted = $this->normalizeIdList([$this->prediction->evicted_houseguest_id]);
            }

            $this->form = [
                'hoh_houseguest_id' => $this->prediction->hoh_houseguest_id,
                'nominee_houseguest_ids' => $this->padToCount($nominees, $nomineeCount),
                'veto_winner_houseguest_id' => $this->prediction->veto_winner_houseguest_id,
                'veto_used' => $this->normalizeVetoUsedSelectValue($this->prediction->veto_used),
                'saved_houseguest_id' => $this->prediction->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->prediction->replacement_nominee_houseguest_id,
                'evicted_houseguest_ids' => $this->padToCount($evicted, $evictedCount),
            ];
        } else {
            $this->form['nominee_houseguest_ids'] = $this->padToCount([], $nomineeCount);
            $this->form['evicted_houseguest_ids'] = $this->padToCount([], $evictedCount);
        }
    }

    private function nomineeCount(): int
    {
        return max(1, (int) ($this->week->nominee_count ?? 2));
    }

    private function evictedCount(): int
    {
        return max(1, (int) ($this->week->evicted_count ?? 1));
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($id): ?int => is_numeric($id) ? (int) $id : null,
            $value,
        )));

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<?int>
     */
    private function padToCount(array $ids, int $count): array
    {
        $padded = array_slice(array_values($ids), 0, $count);

        while (count($padded) < $count) {
            $padded[] = null;
        }

        return $padded;
    }

    public function getIsLockedProperty(): bool
    {
        $confirmed = $this->prediction?->isConfirmed() ?? false;

        return $confirmed || $this->week->isLocked();
    }

    public function updatedFormVetoUsed(mixed $value): void
    {
        $this->form['veto_used'] = $this->normalizeVetoUsedSelectValue($value);

        if ($this->form['veto_used'] !== '1') {
            $this->form['saved_houseguest_id'] = null;
            $this->form['replacement_nominee_houseguest_id'] = null;
        }
    }

    private function normalizeVetoUsedSelectValue(mixed $value): ?string
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => '1',
            $value === false, $value === 0, $value === '0' => '0',
            default => null,
        };
    }

    public function save(): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $rules = [
            'form.hoh_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.nominee_houseguest_ids' => ['array'],
            'form.evicted_houseguest_ids' => ['array'],
            'form.veto_winner_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.veto_used' => ['nullable', 'boolean'],
            'form.saved_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
        ];

        for ($i = 0; $i < $nomineeCount; $i++) {
            $rules["form.nominee_houseguest_ids.$i"] = ['nullable', Rule::in($houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $evictedCount; $i++) {
            $rules["form.evicted_houseguest_ids.$i"] = ['nullable', Rule::in($houseguestIds), 'distinct'];
        }

        $validated = $this->validate($rules);

        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

        $hohId = isset($validated['form']['hoh_houseguest_id']) && is_numeric($validated['form']['hoh_houseguest_id'])
            ? (int) $validated['form']['hoh_houseguest_id']
            : null;

        if ($hohId !== null) {
            $nomineeIds = array_values(array_filter($nominees));
            $evictedIds = array_values(array_filter($evicted));

            if (in_array($hohId, $nomineeIds, true) || in_array($hohId, $evictedIds, true) || ((int) ($validated['form']['veto_winner_houseguest_id'] ?? 0) === $hohId)) {
                $this->addError('form.hoh_houseguest_id', __('HOH (Boss) cannot also be a nominee, veto winner, or evicted.'));

                return;
            }
        }

        if (! ($validated['form']['veto_used'] ?? false)) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $data = array_merge(
            $validated['form'],
            [
                'nominee_houseguest_ids' => $nominees,
                'evicted_houseguest_ids' => $evicted,
                'nominee_1_houseguest_id' => $nominees[0] ?? null,
                'nominee_2_houseguest_id' => $nominees[1] ?? null,
                'evicted_houseguest_id' => $evicted[0] ?? null,
            ],
        );

        $this->prediction = Prediction::query()->updateOrCreate(
            [
                'week_id' => $this->week->id,
                'user_id' => Auth::id(),
            ],
            $data,
        );

        $this->dispatch('prediction-saved');
    }

    public function confirm(): void
    {
        if ($this->isLocked) {
            abort(403);
        }

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $rules = [
            'form.hoh_houseguest_id' => ['required', Rule::in($houseguestIds)],
            'form.nominee_houseguest_ids' => ['array'],
            'form.evicted_houseguest_ids' => ['array'],
            'form.veto_winner_houseguest_id' => ['required', Rule::in($houseguestIds)],
            'form.veto_used' => ['required', 'boolean'],
            'form.saved_houseguest_id' => ['required_if:form.veto_used,1', Rule::in($houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['required_if:form.veto_used,1', Rule::in($houseguestIds)],
        ];

        for ($i = 0; $i < $nomineeCount; $i++) {
            $rules["form.nominee_houseguest_ids.$i"] = ['required', Rule::in($houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $evictedCount; $i++) {
            $rules["form.evicted_houseguest_ids.$i"] = ['required', Rule::in($houseguestIds), 'distinct'];
        }

        $validated = $this->validate($rules);

        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

        $hohId = (int) $validated['form']['hoh_houseguest_id'];
        $vetoWinnerId = (int) $validated['form']['veto_winner_houseguest_id'];
        $nomineeIds = array_values(array_filter($nominees));
        $evictedIds = array_values(array_filter($evicted));

        if (in_array($hohId, $nomineeIds, true) || in_array($hohId, $evictedIds, true) || $vetoWinnerId === $hohId) {
            $this->addError('form.hoh_houseguest_id', __('HOH (Boss) cannot also be a nominee, veto winner, or evicted.'));

            return;
        }

        if (! ($validated['form']['veto_used'] ?? false)) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $data = array_merge(
            $validated['form'],
            [
                'nominee_houseguest_ids' => $nominees,
                'evicted_houseguest_ids' => $evicted,
                'nominee_1_houseguest_id' => $nominees[0] ?? null,
                'nominee_2_houseguest_id' => $nominees[1] ?? null,
                'evicted_houseguest_id' => $evicted[0] ?? null,
            ],
        );

        $prediction = Prediction::query()->updateOrCreate(
            [
                'week_id' => $this->week->id,
                'user_id' => Auth::id(),
            ],
            $data,
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
                        <option value="">—</option>
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    @for ($i = 0; $i < ($week->evicted_count ?? 1); $i++)
                        <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')" :disabled="$this->isLocked" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    @endfor

                    @for ($i = 0; $i < ($week->nominee_count ?? 2); $i++)
                        <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1).' ('.__('In danger').')'" :disabled="$this->isLocked" placeholder="—">
                            <option value="">—</option>
                            @foreach ($houseguests as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                            @endforeach
                        </flux:select>
                    @endfor

                    <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" :disabled="$this->isLocked" placeholder="—">
                        <option value="">—</option>
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="form.veto_used" :label="__('Will the veto be used?')" :disabled="$this->isLocked" placeholder="—">
                        <option value="">—</option>
                        <option value="1">{{ __('Yes') }}</option>
                        <option value="0">{{ __('No') }}</option>
                    </flux:select>

                    <flux:select
                        wire:model="form.saved_houseguest_id"
                        :label="__('If used: Who will be saved?')"
                        :disabled="$this->isLocked || ! $form['veto_used']"
                        placeholder="—"
                    >
                        @foreach ($houseguests as $hg)
                            <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        wire:model="form.replacement_nominee_houseguest_id"
                        :label="__('If used: Replacement nominee')"
                        :disabled="$this->isLocked || ! $form['veto_used']"
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
