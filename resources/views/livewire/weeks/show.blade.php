<?php

use App\Http\Requests\Weeks\ConfirmWeekPredictionRequest;
use App\Http\Requests\Weeks\SaveWeekPredictionRequest;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public Week $week;

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
    ];

    public ?Prediction $prediction = null;

    public function mount(Week $week): void
    {
        $this->week = $week->loadMissing('season');

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $this->prediction = Prediction::query()
            ->where('week_id', $this->week->id)
            ->where('user_id', Auth::id())
            ->first();

        $selectedHouseguestIds = [];
        $isLocked = ($this->prediction?->isConfirmed() ?? false) || $this->week->isLocked();

        if ($this->prediction) {
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

            if ($isLocked) {
                $selectedHouseguestIds = array_values(array_unique(array_merge(
                    $bosses,
                    $nominees,
                    $evicted,
                    $this->normalizeIdList([$this->prediction->veto_winner_houseguest_id]),
                    $this->normalizeIdList([$this->prediction->saved_houseguest_id]),
                    $this->normalizeIdList([$this->prediction->replacement_nominee_houseguest_id]),
                )));
            }

            $this->form = [
                'boss_houseguest_ids' => $this->padToCount($bosses, $bossCount),
                'nominee_houseguest_ids' => $this->padToCount($nominees, $nomineeCount),
                'veto_winner_houseguest_id' => $this->prediction->veto_winner_houseguest_id,
                'veto_used' => $this->normalizeVetoUsedSelectValue($this->prediction->veto_used),
                'saved_houseguest_id' => $this->prediction->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->prediction->replacement_nominee_houseguest_id,
                'evicted_houseguest_ids' => $this->padToCount($evicted, $evictedCount),
            ];
        } else {
            $this->form['boss_houseguest_ids'] = $this->padToCount([], $bossCount);
            $this->form['nominee_houseguest_ids'] = $this->padToCount([], $nomineeCount);
            $this->form['evicted_houseguest_ids'] = $this->padToCount([], $evictedCount);
        }

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->week->season_id)
            ->when(
                $isLocked && $selectedHouseguestIds !== [],
                fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selectedHouseguestIds)),
                fn ($q) => $q->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function bossCount(): int
    {
        return max(1, (int) ($this->week->boss_count ?? 1));
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

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $request = (new SaveWeekPredictionRequest())->setContext(
            $houseguestIds,
            $bossCount,
            $nomineeCount,
            $evictedCount,
            $this->form['veto_used'] ?? null,
        );
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

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
                if (in_array($bossId, $nomineeIds, true) || in_array($bossId, $evictedIds, true) || ($vetoWinnerId !== null && $vetoWinnerId === $bossId)) {
                    $this->addError('form.boss_houseguest_ids', __('Boss cannot also be a nominee, veto winner, or evicted.'));

                    return;
                }
            }
        }

        if (! ($validated['form']['veto_used'] ?? false)) {
            $validated['form']['saved_houseguest_id'] = null;
            $validated['form']['replacement_nominee_houseguest_id'] = null;
        }

        $data = array_merge(
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

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $request = (new ConfirmWeekPredictionRequest())->setContext(
            $houseguestIds,
            $bossCount,
            $nomineeCount,
            $evictedCount,
            $this->form['veto_used'] ?? null,
        );
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $bosses = $this->padToCount($this->normalizeIdList($validated['form']['boss_houseguest_ids'] ?? []), $bossCount);
        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

        $bossIds = array_values(array_filter($bosses));
        $vetoWinnerId = (int) $validated['form']['veto_winner_houseguest_id'];
        $nomineeIds = array_values(array_filter($nominees));
        $evictedIds = array_values(array_filter($evicted));

        foreach ($bossIds as $bossId) {
            if (in_array($bossId, $nomineeIds, true) || in_array($bossId, $evictedIds, true) || $vetoWinnerId === $bossId) {
                $this->addError('form.boss_houseguest_ids', __('Boss cannot also be a nominee, veto winner, or evicted.'));

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
                'boss_houseguest_ids' => $bosses,
                'hoh_houseguest_id' => $bosses[0] ?? null,
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
            </div>

            <flux:button :href="route('weeks.index')" wire:navigate>
                {{ __('All Weeks') }}
            </flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <div class="text-sm">
                    @if ($this->isLocked)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Locked (confirmed or week locked).') }}</span>
                    @else
                        <span class="text-green-600">{{ __('Open — you can edit until you confirm or the week is locked.') }}</span>
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
                <div class="grid gap-6">
                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('HOH') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->boss_count ?? 1); $i++)
                                <flux:select wire:model.live="form.boss_houseguest_ids.{{ $i }}" :label="($week->boss_count ?? 1) > 1 ? __('HOH (Boss) #').($i + 1) : __('HOH (Boss)')" :disabled="$this->isLocked">
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
                            @for ($i = 0; $i < ($week->nominee_count ?? 2); $i++)
                                <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1).' ('.__('In danger').')'" :disabled="$this->isLocked">
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
                            <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')" :disabled="$this->isLocked">
                                <option value="">—</option>
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <div class="hidden md:block md:col-span-2"></div>

                            <flux:select wire:model.live="form.veto_used" :label="__('Will the veto be used?')" :disabled="$this->isLocked">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>

                            @if (($form['veto_used'] ?? null) === '1')
                                <flux:select wire:model="form.saved_houseguest_id" :label="__('If used: Who will be saved?')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Evicted') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->evicted_count ?? 1); $i++)
                                <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')" :disabled="$this->isLocked">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>
                            @endfor
                        </div>
                    </div>
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
