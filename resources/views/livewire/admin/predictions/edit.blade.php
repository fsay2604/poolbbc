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
        'boss_houseguest_ids' => [],
        'nominee_houseguest_ids' => [],
        'veto_winner_houseguest_id' => null,
        'veto_used' => null,
        'saved_houseguest_id' => null,
        'replacement_nominee_houseguest_id' => null,
        'evicted_houseguest_ids' => [],
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

        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();
        $bossCount = $this->bossCount();

        $bosses = $this->normalizeIdList($this->prediction->boss_houseguest_ids);
        if (count($bosses) === 0) {
            $bosses = $this->normalizeIdList([$this->prediction->hoh_houseguest_id]);
        }

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
            'boss_houseguest_ids' => $this->padToCount($bosses, $bossCount),
            'nominee_houseguest_ids' => $this->padToCount($nominees, $nomineeCount),
            'veto_winner_houseguest_id' => $this->prediction->veto_winner_houseguest_id,
            'veto_used' => $this->normalizeVetoUsedSelectValue($this->prediction->veto_used),
            'saved_houseguest_id' => $this->prediction->saved_houseguest_id,
            'replacement_nominee_houseguest_id' => $this->prediction->replacement_nominee_houseguest_id,
            'evicted_houseguest_ids' => $this->padToCount($evicted, $evictedCount),
            'confirmed_at' => $this->prediction->confirmed_at?->format('Y-m-d\TH:i'),
        ];
    }

    private function bossCount(): int
    {
        return max(1, (int) ($this->prediction->week->boss_count ?? 1));
    }

    private function nomineeCount(): int
    {
        return max(1, (int) ($this->prediction->week->nominee_count ?? 2));
    }

    private function evictedCount(): int
    {
        return max(1, (int) ($this->prediction->week->evicted_count ?? 1));
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
        Gate::authorize('admin');

        $houseguestIds = $this->houseguests->pluck('id')->all();

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $rules = [
            'form.boss_houseguest_ids' => ['array'],
            'form.nominee_houseguest_ids' => ['array'],
            'form.evicted_houseguest_ids' => ['array'],
            'form.veto_winner_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.veto_used' => ['nullable', 'boolean'],
            'form.saved_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['nullable', Rule::in($houseguestIds)],
            'form.confirmed_at' => ['nullable', 'date'],
        ];

        for ($i = 0; $i < $bossCount; $i++) {
            $rules["form.boss_houseguest_ids.$i"] = ['nullable', Rule::in($houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $nomineeCount; $i++) {
            $rules["form.nominee_houseguest_ids.$i"] = ['nullable', Rule::in($houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $evictedCount; $i++) {
            $rules["form.evicted_houseguest_ids.$i"] = ['nullable', Rule::in($houseguestIds), 'distinct'];
        }

        $validated = $this->validate($rules);

        $bosses = $this->padToCount($this->normalizeIdList($validated['form']['boss_houseguest_ids'] ?? []), $bossCount);
        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

        $bossIds = array_values(array_filter($bosses));
        if ($bossIds !== []) {
            $nomineeIds = array_values(array_filter($nominees));
            $evictedIds = array_values(array_filter($evicted));
            $vetoWinnerId = is_numeric($validated['form']['veto_winner_houseguest_id'] ?? null)
                ? (int) $validated['form']['veto_winner_houseguest_id']
                : null;

            foreach ($bossIds as $bossId) {
                if (in_array($bossId, $nomineeIds, true)
                    || in_array($bossId, $evictedIds, true)
                    || ($vetoWinnerId !== null && $vetoWinnerId === $bossId)) {
                    $this->addError('form.boss_houseguest_ids', __('Boss cannot also be a nominee, veto winner, or evicted.'));

                    return;
                }
            }
        }

        if (! ($validated['form']['veto_used'] ?? false)) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $this->prediction->fill(array_merge(
            $validated['form'],
            [
                'boss_houseguest_ids' => $bosses,
                'hoh_houseguest_id' => $bosses[0] ?? null,
                'nominee_houseguest_ids' => $nominees,
                'evicted_houseguest_ids' => $evicted,
                'nominee_1_houseguest_id' => $nominees[0] ?? null,
                'nominee_2_houseguest_id' => $nominees[1] ?? null,
                'evicted_houseguest_id' => $evicted[0] ?? null,
            ],
        ));
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
                <div class="grid gap-6">
                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('HOH') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($prediction->week->boss_count ?? 1); $i++)
                                <flux:select wire:model.live="form.boss_houseguest_ids.{{ $i }}" :label="($prediction->week->boss_count ?? 1) > 1 ? __('HOH (Boss) #').($i + 1) : __('HOH (Boss)')" placeholder="—">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Nominees') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($prediction->week->nominee_count ?? 2); $i++)
                                <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1)" placeholder="—">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Veto') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" placeholder="—">
                                <option value="">—</option>
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <div class="hidden md:block md:col-span-2"></div>

                            <flux:select wire:model.live="form.veto_used" :label="__('Veto used?')" placeholder="—">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>

                            <flux:select wire:model.live="form.saved_houseguest_id" :label="__('If used: Saved')" :disabled="! $form['veto_used']" placeholder="—">
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')" :disabled="! $form['veto_used']" placeholder="—">
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Evicted') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($prediction->week->evicted_count ?? 1); $i++)
                                <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($prediction->week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')" placeholder="—">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Confirmation') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <flux:input wire:model="form.confirmed_at" :label="__('Confirmed at (optional)')" type="datetime-local" />
                        </div>
                    </div>
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
