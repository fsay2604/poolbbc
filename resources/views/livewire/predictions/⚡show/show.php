<?php

use App\Actions\Predictions\PredictionVisibility;
use App\Actions\Weeks\WeekPhaseManager;
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
            ->with(['outcome', 'phases'])
            ->where('season_id', $this->season->id)
            ->orderBy('number')
            ->get();

        $this->houseguests = Houseguest::query()
            ->where('season_id', $this->season->id)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $viewer = auth()->user();
        abort_if($viewer === null, 403);

        $visibility = app(PredictionVisibility::class);
        $this->predictions = $visibility->weeklyFor($viewer, $this->user, $this->weeks);
        $this->seasonPrediction = $visibility->seasonFor($viewer, $this->user, $this->season);
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
     * @param  array<int, array<string, mixed>>|null  $payload
     */
    public function payloadEntry(?array $payload, int $phaseId): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! is_numeric($entry['phase_id'] ?? null)) {
                continue;
            }

            if ((int) $entry['phase_id'] === $phaseId) {
                return $entry;
            }
        }

        return null;
    }

    public function modelPhaseEntry(Prediction|WeekOutcome|null $model, Week $week, int $phaseId): ?array
    {
        if ($model === null) {
            return null;
        }

        $payload = $model instanceof Prediction ? $model->phase_picks : $model->phase_results;
        if (! is_array($payload) || $payload === []) {
            $payload = $this->phaseManager()->legacyPayloadForModel($model, $week->phases);
        }

        return $this->payloadEntry($payload, $phaseId);
    }

    /**
     * @return list<int>
     */
    public function payloadIds(?array $entry, string $listKey): array
    {
        if (! is_array($entry)) {
            return [];
        }

        $ids = $entry[$listKey] ?? [];
        if (! is_array($ids)) {
            $ids = [];
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

    public function houseguestName(?int $id): string
    {
        if ($id === null) {
            return '--';
        }

        $houseguest = $this->houseguests->get($id);

        return $houseguest?->name ?? '--';
    }

    public function phaseTypeLabel(string $type): string
    {
        return $this->phaseManager()->phaseTypeLabel($type);
    }

    /**
     * @return array<string, string>
     */
    public function selectionLabels(string $type): array
    {
        return $this->phaseManager()->selectionLabels($type);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    public function isVetoUsed(?array $entry): bool
    {
        return $this->phaseManager()->isVetoUsed($entry);
    }

    public function isVetoDependentListKey(string $listKey): bool
    {
        return $this->phaseManager()->isVetoDependentListKey($listKey);
    }

    private function phaseManager(): WeekPhaseManager
    {
        return app(WeekPhaseManager::class);
    }
};
