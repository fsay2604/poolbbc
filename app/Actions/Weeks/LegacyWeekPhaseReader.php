<?php

namespace App\Actions\Weeks;

use App\Models\Prediction;
use App\Models\WeekOutcome;
use App\Models\WeekPhase;

class LegacyWeekPhaseReader
{
    private const TYPE_HOH = 'hoh';

    private const TYPE_NOMINEES = 'nominees';

    public const TYPE_VETO = 'veto';

    private const TYPE_EVICTIONS = 'evictions';

    /**
     * @return array<string, string>
     */
    public function selectionListKeys(string $type): array
    {
        return match ($type) {
            self::TYPE_HOH => ['hoh_ids' => 'hoh_count'],
            self::TYPE_NOMINEES => ['nominee_ids' => 'nominee_count'],
            self::TYPE_VETO => [
                'winner_ids' => 'winner_count',
                'saved_ids' => 'saved_count',
                'replacement_ids' => 'replacement_count',
            ],
            self::TYPE_EVICTIONS => ['evicted_ids' => 'evicted_count'],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function selectionLabels(string $type): array
    {
        return match ($type) {
            self::TYPE_HOH => ['hoh_ids' => __('Head of Household')],
            self::TYPE_NOMINEES => ['nominee_ids' => __('Nominees')],
            self::TYPE_VETO => [
                'winner_ids' => __('Veto Winners'),
                'saved_ids' => __('Saved'),
                'replacement_ids' => __('Replacement Nominees'),
            ],
            self::TYPE_EVICTIONS => ['evicted_ids' => __('Evictions')],
            default => [],
        };
    }

    public function phaseTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_HOH => __('Head of Household'),
            self::TYPE_NOMINEES => __('Nominees'),
            self::TYPE_VETO => __('Veto'),
            self::TYPE_EVICTIONS => __('Evictions'),
            default => ucfirst($type),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int>
     */
    public function normalizeConfig(string $type, array $values): array
    {
        return match ($type) {
            self::TYPE_HOH => [
                'hoh_count' => $this->normalizeCount($values['hoh_count'] ?? 0),
            ],
            self::TYPE_NOMINEES => [
                'nominee_count' => $this->normalizeCount($values['nominee_count'] ?? 0),
            ],
            self::TYPE_VETO => [
                'winner_count' => $this->normalizeCount($values['winner_count'] ?? 0),
                'saved_count' => $this->normalizeCount($values['saved_count'] ?? 0),
                'replacement_count' => $this->normalizeCount($values['replacement_count'] ?? 0),
            ],
            self::TYPE_EVICTIONS => [
                'evicted_count' => $this->normalizeCount($values['evicted_count'] ?? 0),
            ],
            default => [],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $payload
     * @return list<int>
     */
    public function evictedIds(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $ids = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) !== self::TYPE_EVICTIONS) {
                continue;
            }

            $ids = array_merge($ids, $this->normalizeIdList($entry['evicted_ids'] ?? []));
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    public function payloadByPhaseId(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $mapped = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! is_numeric($entry['phase_id'] ?? null)) {
                continue;
            }

            $mapped[(int) $entry['phase_id']] = $entry;
        }

        return $mapped;
    }

    /**
     * @param  iterable<WeekPhase>  $phases
     * @return list<array<string, mixed>>
     */
    public function legacyPayloadForModel(Prediction|WeekOutcome $model, iterable $phases): array
    {
        $orderedPhases = collect($phases)
            ->sortBy('position')
            ->values();
        $payload = [];
        $typesWithLegacyValues = [];

        foreach ($orderedPhases as $phase) {
            if (! is_string($phase->type)) {
                continue;
            }

            $useLegacyValues = ! in_array($phase->type, $typesWithLegacyValues, true);
            if ($useLegacyValues) {
                $typesWithLegacyValues[] = $phase->type;
            }

            $entry = [
                'phase_id' => (int) $phase->id,
                'position' => (int) $phase->position,
                'type' => (string) $phase->type,
            ];

            if ($phase->type === self::TYPE_HOH) {
                $entry['hoh_ids'] = $useLegacyValues
                    ? $this->legacyList($model->boss_houseguest_ids ?? null, [$model->hoh_houseguest_id ?? null])
                    : [];

                $payload[] = $entry;

                continue;
            }

            if ($phase->type === self::TYPE_NOMINEES) {
                $entry['nominee_ids'] = $useLegacyValues
                    ? $this->legacyList(
                        $model->nominee_houseguest_ids ?? null,
                        [
                            $model->nominee_1_houseguest_id ?? null,
                            $model->nominee_2_houseguest_id ?? null,
                        ],
                    )
                    : [];

                $payload[] = $entry;

                continue;
            }

            if ($phase->type === self::TYPE_VETO) {
                $vetoUsed = $useLegacyValues ? $this->toBoolOrNull($model->veto_used ?? null) : false;

                $entry['veto_used'] = $vetoUsed;
                $entry['winner_ids'] = $useLegacyValues
                    ? $this->legacyList(null, [$model->veto_winner_houseguest_id ?? null])
                    : [];
                $entry['saved_ids'] = $vetoUsed === true
                    ? $this->legacyList(null, [$model->saved_houseguest_id ?? null])
                    : [];
                $entry['replacement_ids'] = $vetoUsed === true
                    ? $this->legacyList(null, [$model->replacement_nominee_houseguest_id ?? null])
                    : [];

                $payload[] = $entry;

                continue;
            }

            if ($phase->type === self::TYPE_EVICTIONS) {
                $entry['evicted_ids'] = $useLegacyValues
                    ? $this->legacyList($model->evicted_houseguest_ids ?? null, [$model->evicted_houseguest_id ?? null])
                    : [];
            }

            $payload[] = $entry;
        }

        return $payload;
    }

    /**
     * @return list<int>
     */
    public function normalizeIdList(mixed $value): array
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
     * @param  array<string, mixed>|null  $entry
     */
    public function vetoUsage(?array $entry): ?bool
    {
        if (! is_array($entry)) {
            return null;
        }

        $explicit = $this->toBoolOrNull($entry['veto_used'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->normalizeIdList($entry['used_ids'] ?? []) !== []
            || $this->normalizeIdList($entry['saved_ids'] ?? []) !== []
            || $this->normalizeIdList($entry['replacement_ids'] ?? []) !== []
            ? true
            : null;
    }

    private function normalizeCount(mixed $value): int
    {
        $count = is_numeric($value) ? (int) $value : 0;

        return max(0, min(20, $count));
    }

    /**
     * @param  list<mixed>  $fallback
     * @return list<int>
     */
    private function legacyList(mixed $primary, array $fallback): array
    {
        $ids = $this->normalizeIdList($primary);

        if ($ids !== []) {
            return $ids;
        }

        return $this->normalizeIdList($fallback);
    }

    private function toBoolOrNull(mixed $value): ?bool
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true', $value === 'on', $value === 'yes' => true,
            $value === 0, $value === '0', $value === 'false', $value === 'off', $value === 'no' => false,
            default => null,
        };
    }
}
