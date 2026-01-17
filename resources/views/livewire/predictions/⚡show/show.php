<?php

use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public Collection $weeks;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public Collection $houseguests;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Prediction> */
    public Collection $predictions;

    public ?SeasonPrediction $seasonPrediction = null;

    public function mount(User $user): void
    {
        $this->user = $user;

        $this->season = Season::query()->where('is_active', true)->first();

        if (! $this->season) {
            $this->weeks = collect();
            $this->houseguests = collect();
            $this->predictions = collect();

            return;
        }

        $this->weeks = Week::query()
            ->with('outcome')
            ->where('season_id', $this->season->id)
            ->orderBy('number')
            ->get();

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $this->predictions = Prediction::query()
            ->with('score')
            ->where('user_id', $this->user->id)
            ->whereIn('week_id', $this->weeks->pluck('id'))
            ->get()
            ->keyBy('week_id');

        $this->seasonPrediction = SeasonPrediction::query()
            ->where('season_id', $this->season->id)
            ->where('user_id', $this->user->id)
            ->first();
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    public function normalizeIdList(array $ids): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
            $ids,
        )));

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return list<int>
     */
    public function bossIds(?Prediction $prediction): array
    {
        if (! $prediction) {
            return [];
        }

        $ids = is_array($prediction->boss_houseguest_ids) ? $prediction->boss_houseguest_ids : [];

        if ($ids === [] && $prediction->hoh_houseguest_id !== null) {
            $ids = [$prediction->hoh_houseguest_id];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @return list<int>
     */
    public function nomineeIds(?Prediction $prediction): array
    {
        if (! $prediction) {
            return [];
        }

        $ids = is_array($prediction->nominee_houseguest_ids) ? $prediction->nominee_houseguest_ids : [];

        if ($ids === []) {
            $ids = [
                $prediction->nominee_1_houseguest_id,
                $prediction->nominee_2_houseguest_id,
            ];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @return list<int>
     */
    public function evictedIds(?Prediction $prediction): array
    {
        if (! $prediction) {
            return [];
        }

        $ids = is_array($prediction->evicted_houseguest_ids) ? $prediction->evicted_houseguest_ids : [];

        if ($ids === [] && $prediction->evicted_houseguest_id !== null) {
            $ids = [$prediction->evicted_houseguest_id];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @return list<int>
     */
    public function outcomeBossIds(?WeekOutcome $outcome): array
    {
        if (! $outcome) {
            return [];
        }

        $ids = is_array($outcome->boss_houseguest_ids) ? $outcome->boss_houseguest_ids : [];

        if ($ids === [] && $outcome->hoh_houseguest_id !== null) {
            $ids = [$outcome->hoh_houseguest_id];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @return list<int>
     */
    public function outcomeNomineeIds(?WeekOutcome $outcome): array
    {
        if (! $outcome) {
            return [];
        }

        $ids = is_array($outcome->nominee_houseguest_ids) ? $outcome->nominee_houseguest_ids : [];

        if ($ids === []) {
            $ids = [
                $outcome->nominee_1_houseguest_id,
                $outcome->nominee_2_houseguest_id,
            ];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @return list<int>
     */
    public function outcomeEvictedIds(?WeekOutcome $outcome): array
    {
        if (! $outcome) {
            return [];
        }

        $ids = is_array($outcome->evicted_houseguest_ids) ? $outcome->evicted_houseguest_ids : [];

        if ($ids === [] && $outcome->evicted_houseguest_id !== null) {
            $ids = [$outcome->evicted_houseguest_id];
        }

        return $this->normalizeIdList($ids);
    }

    /**
     * @param  list<int>  $actualIds
     */
    public function isCorrectPick(?int $predictedId, array $actualIds): bool
    {
        if ($predictedId === null || $actualIds === []) {
            return false;
        }

        return in_array($predictedId, $actualIds, true);
    }

    public function isCorrectBoolean(?bool $predictedValue, ?bool $actualValue): bool
    {
        if ($predictedValue === null || $actualValue === null) {
            return false;
        }

        return $predictedValue === $actualValue;
    }

    public function houseguestName(?int $id): string
    {
        if ($id === null) {
            return '--';
        }

        $houseguest = $this->houseguests->get($id);

        return $houseguest?->name ?? '--';
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function houseguestNames(array $ids): array
    {
        return array_values(array_map(
            fn (int $id): string => $this->houseguestName($id),
            $ids,
        ));
    }
};
