<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $poolEventScoringConfig = DB::table('pool_events')
            ->select('scoring_config')
            ->whereColumn('pool_events.season_event_id', 'season_events.id')
            ->orderBy('pool_events.id')
            ->limit(1);

        DB::table('season_events')
            ->leftJoin('event_types', 'event_types.id', '=', 'season_events.event_type_id')
            ->select(['season_events.id', 'event_types.default_config'])
            ->addSelect(['pool_event_scoring_config' => $poolEventScoringConfig])
            ->orderBy('season_events.id')
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    $sourceConfig = $this->hasStoredConfig($event->default_config)
                        ? $event->default_config
                        : $event->pool_event_scoring_config;
                    DB::table('season_events')
                        ->where('id', $event->id)
                        ->update([
                            'scoring_config' => json_encode(
                                $this->normalizeScoringConfig($this->decodeConfig($sourceConfig)),
                                JSON_THROW_ON_ERROR,
                            ),
                        ]);
                }
            }, 'season_events.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('season_events')->update(['scoring_config' => null]);
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (! is_string($config) || $config === '') {
            return [];
        }

        $decoded = json_decode($config, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function hasStoredConfig(mixed $config): bool
    {
        if (is_array($config)) {
            return true;
        }

        return is_string($config)
            && trim($config) !== ''
            && trim($config) !== 'null';
    }

    /** @param array<string, mixed> $config */
    private function normalizeScoringConfig(array $config): array
    {
        $owner = is_array($config['owner'] ?? null) ? $config['owner'] : [];
        $prediction = is_array($config['prediction'] ?? null) ? $config['prediction'] : [];

        return [
            'owner' => [
                'points_per_match' => (int) ($owner['points_per_match'] ?? 1),
            ],
            'prediction' => [
                'points_per_correct' => (int) ($prediction['points_per_correct'] ?? 1),
                'exact_match_bonus' => (int) ($prediction['exact_match_bonus'] ?? 0),
                'wrong_answer_penalty' => (int) ($prediction['wrong_answer_penalty'] ?? 0),
            ],
            'allow_negative' => (bool) ($config['allow_negative'] ?? false),
        ];
    }
};
