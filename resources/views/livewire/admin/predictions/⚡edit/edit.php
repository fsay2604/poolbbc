<?php

use App\Http\Requests\Admin\UpdatePredictionRequest;
use App\Models\Houseguest;
use App\Models\Prediction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

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

        $request = (new UpdatePredictionRequest())->setContext($houseguestIds, $bossCount, $nomineeCount, $evictedCount);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $bosses = $this->padToCount($this->normalizeIdList($validated['form']['boss_houseguest_ids'] ?? []), $bossCount);
        $nominees = $this->padToCount($this->normalizeIdList($validated['form']['nominee_houseguest_ids'] ?? []), $nomineeCount);
        $evicted = $this->padToCount($this->normalizeIdList($validated['form']['evicted_houseguest_ids'] ?? []), $evictedCount);

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
};
