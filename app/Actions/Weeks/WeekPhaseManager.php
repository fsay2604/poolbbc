<?php

namespace App\Actions\Weeks;

use App\Models\Week;
use App\Models\WeekPhase;

class WeekPhaseManager
{
    public const TYPE_HOH = 'hoh';

    public const TYPE_NOMINEES = 'nominees';

    public const TYPE_VETO = 'veto';

    public const TYPE_EVICTIONS = 'evictions';

    /**
     * @return list<string>
     */
    public function phaseTypes(): array
    {
        return [
            self::TYPE_HOH,
            self::TYPE_NOMINEES,
            self::TYPE_VETO,
            self::TYPE_EVICTIONS,
        ];
    }

    /**
     * @return list<array{position:int, type:string, config:array<string, int>}>
     */
    public function defaultPhaseDefinitions(array $overrides = []): array
    {
        $hohCount = $this->normalizeCount($overrides['hoh_count'] ?? 1);
        $nomineeCount = $this->normalizeCount($overrides['nominee_count'] ?? 2);
        $evictedCount = $this->normalizeCount($overrides['evicted_count'] ?? 1);

        return [
            [
                'position' => 1,
                'type' => self::TYPE_HOH,
                'config' => ['hoh_count' => $hohCount],
            ],
            [
                'position' => 2,
                'type' => self::TYPE_NOMINEES,
                'config' => ['nominee_count' => $nomineeCount],
            ],
            [
                'position' => 3,
                'type' => self::TYPE_VETO,
                'config' => [
                    'winner_count' => 1,
                    'saved_count' => 1,
                    'replacement_count' => 1,
                ],
            ],
            [
                'position' => 4,
                'type' => self::TYPE_EVICTIONS,
                'config' => ['evicted_count' => $evictedCount],
            ],
        ];
    }

    public function ensureDefaultPhases(Week $week): void
    {
        if ($week->phases()->exists()) {
            return;
        }

        $week->phases()->createMany($this->defaultPhaseDefinitions([
            'hoh_count' => is_numeric($week->getAttribute('boss_count')) ? (int) $week->getAttribute('boss_count') : 1,
            'nominee_count' => is_numeric($week->getAttribute('nominee_count')) ? (int) $week->getAttribute('nominee_count') : 2,
            'evicted_count' => is_numeric($week->getAttribute('evicted_count')) ? (int) $week->getAttribute('evicted_count') : 1,
        ]));
    }

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
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{id:?int, position:int, type:string, config:array<string, int>}>
     */
    public function normalizeWeekFormRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $index => $row) {
            $type = in_array($row['type'] ?? '', $this->phaseTypes(), true)
                ? (string) $row['type']
                : self::TYPE_HOH;

            $normalized[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                'position' => max(1, (int) ($row['position'] ?? ($index + 1))),
                'type' => $type,
                'config' => $this->normalizeConfig($type, $row),
            ];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => $a['position'] <=> $b['position'],
        );

        return array_values($normalized);
    }

    /**
     * @param  iterable<WeekPhase>  $phases
     * @param  array<int, array<string, mixed>>|null  $payload
     * @return list<array<string, mixed>>
     */
    public function buildSelectionRows(iterable $phases, ?array $payload): array
    {
        $payloadByPhaseId = $this->payloadByPhaseId($payload);

        $rows = [];

        foreach ($phases as $phase) {
            $config = $this->normalizeConfig($phase->type, is_array($phase->config) ? $phase->config : []);
            $entry = $payloadByPhaseId[$phase->id] ?? [];

            $row = [
                'phase_id' => $phase->id,
                'position' => $phase->position,
                'type' => $phase->type,
            ];

            $vetoUsed = $phase->type === self::TYPE_VETO
                ? $this->isVetoUsed(is_array($entry) ? $entry : null)
                : false;

            if ($phase->type === self::TYPE_VETO) {
                $row['veto_used'] = $vetoUsed;
            }

            foreach ($this->selectionListKeys($phase->type) as $listKey => $countKey) {
                $count = (int) ($config[$countKey] ?? 0);

                if ($phase->type === self::TYPE_VETO && $this->isVetoDependentListKey($listKey) && ! $vetoUsed) {
                    $row[$listKey] = $this->padToCount([], $count);

                    continue;
                }

                $row[$listKey] = $this->padToCount(
                    $this->normalizeIdList($entry[$listKey] ?? []),
                    $count
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  iterable<WeekPhase>  $phases
     * @return list<array<string, mixed>>
     */
    public function normalizeSelectionRows(array $rows, iterable $phases): array
    {
        /** @var array<int, WeekPhase> $phaseById */
        $phaseById = collect($phases)->keyBy('id')->all();
        $rowsByPhaseId = [];

        foreach ($rows as $row) {
            $phaseId = is_numeric($row['phase_id'] ?? null) ? (int) $row['phase_id'] : null;
            if ($phaseId === null) {
                continue;
            }

            $rowsByPhaseId[$phaseId] = $row;
        }

        $normalized = [];

        foreach ($phaseById as $phaseId => $phase) {
            $config = $this->normalizeConfig($phase->type, is_array($phase->config) ? $phase->config : []);
            $row = $rowsByPhaseId[$phaseId] ?? [];

            $entry = [
                'phase_id' => $phaseId,
                'position' => (int) $phase->position,
                'type' => (string) $phase->type,
            ];

            $vetoUsed = $phase->type === self::TYPE_VETO
                ? $this->normalizeVetoUsedValue(is_array($row) ? ($row['veto_used'] ?? false) : false)
                : false;

            if ($phase->type === self::TYPE_VETO) {
                $entry['veto_used'] = $vetoUsed;
            }

            foreach ($this->selectionListKeys($phase->type) as $listKey => $countKey) {
                $count = (int) ($config[$countKey] ?? 0);

                if ($phase->type === self::TYPE_VETO && $this->isVetoDependentListKey($listKey) && ! $vetoUsed) {
                    $entry[$listKey] = [];

                    continue;
                }

                $entry[$listKey] = $this->padToCount(
                    $this->normalizeIdList($row[$listKey] ?? []),
                    $count
                );
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  iterable<WeekPhase>  $phases
     * @return list<array{phase_id:int, position:int, type:string, lists:array<string, int>}>
     */
    public function phaseDefinitionsForValidation(iterable $phases): array
    {
        $definitions = [];

        foreach ($phases as $phase) {
            $config = $this->normalizeConfig($phase->type, is_array($phase->config) ? $phase->config : []);
            $lists = [];

            foreach ($this->selectionListKeys($phase->type) as $listKey => $countKey) {
                $lists[$listKey] = (int) ($config[$countKey] ?? 0);
            }

            $definitions[] = [
                'phase_id' => (int) $phase->id,
                'position' => (int) $phase->position,
                'type' => (string) $phase->type,
                'lists' => $lists,
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $payload
     * @return list<int>
     */
    public function selectedHouseguestIds(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $ids = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryType = is_string($entry['type'] ?? null) ? $entry['type'] : null;
            $entryVetoUsed = $entryType === self::TYPE_VETO
                ? $this->vetoUsage($entry)
                : null;

            foreach ($entry as $key => $value) {
                if (! is_string($key) || ! str_ends_with($key, '_ids')) {
                    continue;
                }

                if ($entryType === self::TYPE_VETO && $this->isVetoDependentListKey($key) && $entryVetoUsed !== true) {
                    continue;
                }

                $ids = array_merge($ids, $this->normalizeIdList($value));
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
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
     */
    public function maxPoints(?array $payload): int
    {
        if (! is_array($payload)) {
            return 0;
        }

        $points = 0;

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryType = is_string($entry['type'] ?? null) ? $entry['type'] : null;
            $entryVetoUsed = $entryType === self::TYPE_VETO
                ? $this->vetoUsage($entry)
                : null;

            if ($entryType === self::TYPE_VETO && $entryVetoUsed !== null) {
                $points += 1;
            }

            foreach ($entry as $key => $value) {
                if (! is_string($key) || ! str_ends_with($key, '_ids')) {
                    continue;
                }

                if ($entryType === self::TYPE_VETO && $this->isVetoDependentListKey($key) && $entryVetoUsed !== true) {
                    continue;
                }

                $points += count($this->normalizeIdList($value));
            }
        }

        return $points;
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
     * @param  array<int, array<string, mixed>>|null  $payload
     * @return list<array<string, mixed>>
     */
    public function reconcilePayload(iterable $phases, ?array $payload): array
    {
        return $this->normalizeSelectionRows(
            $this->buildSelectionRows($phases, $payload),
            $phases
        );
    }

    /**
     * @param  iterable<WeekPhase>  $phases
     * @return list<array<string, mixed>>
     */
    public function legacyPayloadForModel(mixed $model, iterable $phases): array
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

    public function isVetoDependentListKey(string $listKey): bool
    {
        return in_array($listKey, ['saved_ids', 'replacement_ids'], true);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    public function isVetoUsed(?array $entry): bool
    {
        return $this->vetoUsage($entry) ?? false;
    }

    public function normalizeVetoUsedValue(mixed $value): bool
    {
        return $this->toBoolOrNull($value) ?? false;
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
