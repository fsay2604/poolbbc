<?php

use App\Http\Requests\Admin\SaveWeekOutcomeRequest;
use App\Models\Houseguest;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Actions\Seasons\CalculateSeasonOutcomeFromWeekOutcomes;
use App\Actions\Predictions\ScoreSeasonPredictions;
use Livewire\Volt\Component;

new class extends Component {
    public Week $week;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public ?WeekOutcome $outcome = null;

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

    public function mount(Week $week): void
    {
        Gate::authorize('admin');

        $this->week = $week->loadMissing('season', 'outcome');
        $this->outcome = $this->week->outcome;

        $bossCount = $this->bossCount();
        $nomineeCount = $this->nomineeCount();
        $evictedCount = $this->evictedCount();

        $selectedHouseguestIds = [];

        if ($this->outcome) {
            $bosses = $this->normalizeIdList($this->outcome->boss_houseguest_ids);
            if (count($bosses) === 0) {
                $bosses = $this->normalizeIdList([$this->outcome->hoh_houseguest_id]);
            }

            $nominees = $this->normalizeIdList($this->outcome->nominee_houseguest_ids);
            if (count($nominees) === 0) {
                $nominees = $this->normalizeIdList([
                    $this->outcome->nominee_1_houseguest_id,
                    $this->outcome->nominee_2_houseguest_id,
                ]);
            }

            $evicted = $this->normalizeIdList($this->outcome->evicted_houseguest_ids);
            if (count($evicted) === 0) {
                $evicted = $this->normalizeIdList([$this->outcome->evicted_houseguest_id]);
            }

            $selectedHouseguestIds = array_values(array_unique(array_merge(
                $bosses,
                $nominees,
                $evicted,
                $this->normalizeIdList([$this->outcome->veto_winner_houseguest_id]),
                $this->normalizeIdList([$this->outcome->saved_houseguest_id]),
                $this->normalizeIdList([$this->outcome->replacement_nominee_houseguest_id]),
            )));

            $this->form = [
                'boss_houseguest_ids' => $this->padToCount($bosses, $bossCount),
                'nominee_houseguest_ids' => $this->padToCount($nominees, $nomineeCount),
                'veto_winner_houseguest_id' => $this->outcome->veto_winner_houseguest_id,
                'veto_used' => $this->normalizeVetoUsedSelectValue($this->outcome->veto_used),
                'saved_houseguest_id' => $this->outcome->saved_houseguest_id,
                'replacement_nominee_houseguest_id' => $this->outcome->replacement_nominee_houseguest_id,
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
                $selectedHouseguestIds !== [],
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

        $request = (new SaveWeekOutcomeRequest())->setContext($houseguestIds, $bossCount, $nomineeCount, $evictedCount);
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

        $outcome = WeekOutcome::query()->updateOrCreate(
            ['week_id' => $this->week->id],
            array_merge(
                $data,
                [
                    'last_admin_edited_by_user_id' => Auth::id(),
                    'last_admin_edited_at' => now(),
                ],
            ),
        );

        $evictedIds = array_values(array_filter($this->normalizeIdList($evicted)));
        if ($evictedIds !== []) {
            Houseguest::query()
                ->where('season_id', $this->week->season_id)
                ->whereIn('id', $evictedIds)
                ->update(['is_active' => false]);
        }

        if ($this->week->season) {
            (new CalculateSeasonOutcomeFromWeekOutcomes())->execute($this->week->season);

            $admin = Auth::user();
            app(ScoreSeasonPredictions::class)->run($this->week->season->refresh(), $admin);
        }

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
                <div class="grid gap-6">
                    <div class="grid gap-3">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('HOH') }}</div>
                        <div class="grid gap-4 md:grid-cols-3">
                            @for ($i = 0; $i < ($week->boss_count ?? 1); $i++)
                                <flux:select wire:model.live="form.boss_houseguest_ids.{{ $i }}" :label="($week->boss_count ?? 1) > 1 ? __('HOH (Boss) #').($i + 1) : __('HOH (Boss)')">
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
                                <flux:select wire:model.live="form.nominee_houseguest_ids.{{ $i }}" :label="__('Nominee #').($i + 1)">
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
                            <flux:select wire:model="form.veto_winner_houseguest_id" :label="__('Veto Winner')">
                                <option value="">—</option>
                                @foreach ($houseguests as $hg)
                                    <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                @endforeach
                            </flux:select>

                            <div class="hidden md:block md:col-span-2"></div>

                            <flux:select wire:model.live="form.veto_used" :label="__('Veto used?')">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>

                            @if (($form['veto_used'] ?? null) === '1')
                                <flux:select wire:model.live="form.saved_houseguest_id" :label="__('If used: Saved')">
                                    <option value="">—</option>
                                    @foreach ($houseguests as $hg)
                                        <option value="{{ $hg->id }}">{{ $hg->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model.live="form.replacement_nominee_houseguest_id" :label="__('If used: Replacement nominee')">
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
                                <flux:select wire:model.live="form.evicted_houseguest_ids.{{ $i }}" :label="($week->evicted_count ?? 1) > 1 ? __('Evicted #').($i + 1) : __('Evicted')">
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
                    <flux:button variant="primary" type="submit">{{ __('Save Outcome') }}</flux:button>
                    <x-action-message on="outcome-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                </div>
            </form>
        </div>
    </div>
</section>
