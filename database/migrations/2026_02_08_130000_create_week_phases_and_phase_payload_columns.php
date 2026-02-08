<?php

use App\Actions\Weeks\WeekPhaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('week_phases')) {
            Schema::create('week_phases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('week_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('type', 40);
                $table->json('config');
                $table->timestamps();

                $table->unique(['week_id', 'position']);
                $table->index(['week_id', 'type']);
            });
        }

        if (! Schema::hasColumn('predictions', 'phase_picks')) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->json('phase_picks')->nullable()->after('user_id');
            });
        }

        if (! Schema::hasColumn('week_outcomes', 'phase_results')) {
            Schema::table('week_outcomes', function (Blueprint $table) {
                $table->json('phase_results')->nullable()->after('week_id');
            });
        }

        $this->backfillWeekPhases();
        $this->backfillPredictionPayloads();
        $this->backfillOutcomePayloads();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('week_outcomes', 'phase_results')) {
            Schema::table('week_outcomes', function (Blueprint $table) {
                $table->dropColumn('phase_results');
            });
        }

        if (Schema::hasColumn('predictions', 'phase_picks')) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropColumn('phase_picks');
            });
        }

        Schema::dropIfExists('week_phases');
    }

    private function backfillWeekPhases(): void
    {
        $now = now();
        $columns = ['id'];
        foreach (['boss_count', 'nominee_count', 'evicted_count'] as $column) {
            if (Schema::hasColumn('weeks', $column)) {
                $columns[] = $column;
            }
        }

        $weeks = DB::table('weeks')->select($columns)->get();

        foreach ($weeks as $week) {
            $existing = DB::table('week_phases')->where('week_id', $week->id)->exists();
            if ($existing) {
                continue;
            }

            DB::table('week_phases')->insert([
                [
                    'week_id' => $week->id,
                    'position' => 1,
                    'type' => WeekPhaseManager::TYPE_HOH,
                    'config' => json_encode([
                        'hoh_count' => $this->normalizeCount($this->legacyValue($week, 'boss_count') ?? 1),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'week_id' => $week->id,
                    'position' => 2,
                    'type' => WeekPhaseManager::TYPE_NOMINEES,
                    'config' => json_encode([
                        'nominee_count' => $this->normalizeCount($this->legacyValue($week, 'nominee_count') ?? 2),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'week_id' => $week->id,
                    'position' => 3,
                    'type' => WeekPhaseManager::TYPE_VETO,
                    'config' => json_encode([
                        'winner_count' => 1,
                        'saved_count' => 1,
                        'replacement_count' => 1,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'week_id' => $week->id,
                    'position' => 4,
                    'type' => WeekPhaseManager::TYPE_EVICTIONS,
                    'config' => json_encode([
                        'evicted_count' => $this->normalizeCount($this->legacyValue($week, 'evicted_count') ?? 1),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    private function backfillPredictionPayloads(): void
    {
        $columns = ['id', 'week_id'];
        $legacyColumns = [
            'hoh_houseguest_id',
            'boss_houseguest_ids',
            'nominee_1_houseguest_id',
            'nominee_2_houseguest_id',
            'nominee_houseguest_ids',
            'veto_winner_houseguest_id',
            'veto_used',
            'saved_houseguest_id',
            'replacement_nominee_houseguest_id',
            'evicted_houseguest_id',
            'evicted_houseguest_ids',
        ];

        foreach ($legacyColumns as $column) {
            if (Schema::hasColumn('predictions', $column)) {
                $columns[] = $column;
            }
        }

        $predictions = DB::table('predictions')->select($columns)->get();

        foreach ($predictions as $prediction) {
            $phasePayload = $this->buildLegacyPayload(
                (int) $prediction->week_id,
                [
                    'hoh_houseguest_id' => $this->legacyValue($prediction, 'hoh_houseguest_id'),
                    'boss_houseguest_ids' => $this->legacyValue($prediction, 'boss_houseguest_ids'),
                    'nominee_1_houseguest_id' => $this->legacyValue($prediction, 'nominee_1_houseguest_id'),
                    'nominee_2_houseguest_id' => $this->legacyValue($prediction, 'nominee_2_houseguest_id'),
                    'nominee_houseguest_ids' => $this->legacyValue($prediction, 'nominee_houseguest_ids'),
                    'veto_winner_houseguest_id' => $this->legacyValue($prediction, 'veto_winner_houseguest_id'),
                    'veto_used' => $this->legacyValue($prediction, 'veto_used'),
                    'saved_houseguest_id' => $this->legacyValue($prediction, 'saved_houseguest_id'),
                    'replacement_nominee_houseguest_id' => $this->legacyValue($prediction, 'replacement_nominee_houseguest_id'),
                    'evicted_houseguest_id' => $this->legacyValue($prediction, 'evicted_houseguest_id'),
                    'evicted_houseguest_ids' => $this->legacyValue($prediction, 'evicted_houseguest_ids'),
                ],
            );

            DB::table('predictions')
                ->where('id', $prediction->id)
                ->update([
                    'phase_picks' => json_encode($phasePayload, JSON_THROW_ON_ERROR),
                ]);
        }
    }

    private function backfillOutcomePayloads(): void
    {
        $columns = ['id', 'week_id'];
        $legacyColumns = [
            'hoh_houseguest_id',
            'boss_houseguest_ids',
            'nominee_1_houseguest_id',
            'nominee_2_houseguest_id',
            'nominee_houseguest_ids',
            'veto_winner_houseguest_id',
            'veto_used',
            'saved_houseguest_id',
            'replacement_nominee_houseguest_id',
            'evicted_houseguest_id',
            'evicted_houseguest_ids',
        ];

        foreach ($legacyColumns as $column) {
            if (Schema::hasColumn('week_outcomes', $column)) {
                $columns[] = $column;
            }
        }

        $outcomes = DB::table('week_outcomes')->select($columns)->get();

        foreach ($outcomes as $outcome) {
            $phasePayload = $this->buildLegacyPayload(
                (int) $outcome->week_id,
                [
                    'hoh_houseguest_id' => $this->legacyValue($outcome, 'hoh_houseguest_id'),
                    'boss_houseguest_ids' => $this->legacyValue($outcome, 'boss_houseguest_ids'),
                    'nominee_1_houseguest_id' => $this->legacyValue($outcome, 'nominee_1_houseguest_id'),
                    'nominee_2_houseguest_id' => $this->legacyValue($outcome, 'nominee_2_houseguest_id'),
                    'nominee_houseguest_ids' => $this->legacyValue($outcome, 'nominee_houseguest_ids'),
                    'veto_winner_houseguest_id' => $this->legacyValue($outcome, 'veto_winner_houseguest_id'),
                    'veto_used' => $this->legacyValue($outcome, 'veto_used'),
                    'saved_houseguest_id' => $this->legacyValue($outcome, 'saved_houseguest_id'),
                    'replacement_nominee_houseguest_id' => $this->legacyValue($outcome, 'replacement_nominee_houseguest_id'),
                    'evicted_houseguest_id' => $this->legacyValue($outcome, 'evicted_houseguest_id'),
                    'evicted_houseguest_ids' => $this->legacyValue($outcome, 'evicted_houseguest_ids'),
                ],
            );

            DB::table('week_outcomes')
                ->where('id', $outcome->id)
                ->update([
                    'phase_results' => json_encode($phasePayload, JSON_THROW_ON_ERROR),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return list<array<string, mixed>>
     */
    private function buildLegacyPayload(int $weekId, array $legacy): array
    {
        $phases = DB::table('week_phases')
            ->where('week_id', $weekId)
            ->orderBy('position')
            ->get()
            ->keyBy('type');

        $payload = [];

        $hohPhase = $phases->get(WeekPhaseManager::TYPE_HOH);
        if ($hohPhase !== null) {
            $payload[] = [
                'phase_id' => (int) $hohPhase->id,
                'position' => (int) $hohPhase->position,
                'type' => WeekPhaseManager::TYPE_HOH,
                'hoh_ids' => $this->fallbackList($legacy['boss_houseguest_ids'] ?? null, $legacy['hoh_houseguest_id'] ?? null),
            ];
        }

        $nomineesPhase = $phases->get(WeekPhaseManager::TYPE_NOMINEES);
        if ($nomineesPhase !== null) {
            $payload[] = [
                'phase_id' => (int) $nomineesPhase->id,
                'position' => (int) $nomineesPhase->position,
                'type' => WeekPhaseManager::TYPE_NOMINEES,
                'nominee_ids' => $this->fallbackList(
                    $legacy['nominee_houseguest_ids'] ?? null,
                    [
                        $legacy['nominee_1_houseguest_id'] ?? null,
                        $legacy['nominee_2_houseguest_id'] ?? null,
                    ]
                ),
            ];
        }

        $vetoPhase = $phases->get(WeekPhaseManager::TYPE_VETO);
        if ($vetoPhase !== null) {
            $vetoUsed = $this->isTruthy($legacy['veto_used'] ?? null);

            $payload[] = [
                'phase_id' => (int) $vetoPhase->id,
                'position' => (int) $vetoPhase->position,
                'type' => WeekPhaseManager::TYPE_VETO,
                'veto_used' => $vetoUsed,
                'winner_ids' => $this->fallbackList(null, $legacy['veto_winner_houseguest_id'] ?? null),
                'saved_ids' => $vetoUsed
                    ? $this->fallbackList(null, $legacy['saved_houseguest_id'] ?? null)
                    : [],
                'replacement_ids' => $vetoUsed
                    ? $this->fallbackList(null, $legacy['replacement_nominee_houseguest_id'] ?? null)
                    : [],
            ];
        }

        $evictionsPhase = $phases->get(WeekPhaseManager::TYPE_EVICTIONS);
        if ($evictionsPhase !== null) {
            $payload[] = [
                'phase_id' => (int) $evictionsPhase->id,
                'position' => (int) $evictionsPhase->position,
                'type' => WeekPhaseManager::TYPE_EVICTIONS,
                'evicted_ids' => $this->fallbackList($legacy['evicted_houseguest_ids'] ?? null, $legacy['evicted_houseguest_id'] ?? null),
            ];
        }

        return $payload;
    }

    /**
     * @return list<int>
     */
    private function fallbackList(mixed $primary, mixed $fallback): array
    {
        $ids = $this->normalizeIdList($primary);

        if ($ids !== []) {
            return $ids;
        }

        return $this->normalizeIdList($fallback);
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

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

    private function normalizeCount(mixed $value): int
    {
        $count = is_numeric($value) ? (int) $value : 0;

        return max(0, min(20, $count));
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function legacyValue(object $row, string $property): mixed
    {
        return property_exists($row, $property) ? $row->{$property} : null;
    }
};
