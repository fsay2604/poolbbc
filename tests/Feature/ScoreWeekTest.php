<?php

use App\Actions\Predictions\ScoreWeek;
use App\Actions\Weeks\WeekPhaseManager;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;

/**
 * @param  array<string, mixed>  $values
 * @return list<array<string, mixed>>
 */
function scoreWeekPayloadForValues(Week $week, array $values): array
{
    $week->loadMissing('phases');

    return $week->phases
        ->sortBy('position')
        ->values()
        ->map(function ($phase) use ($values): array {
            $entry = [
                'phase_id' => $phase->id,
                'position' => $phase->position,
                'type' => $phase->type,
            ];

            if ($phase->type === WeekPhaseManager::TYPE_HOH) {
                $entry['hoh_ids'] = $values['hoh_ids'] ?? [];

                return $entry;
            }

            if ($phase->type === WeekPhaseManager::TYPE_NOMINEES) {
                $entry['nominee_ids'] = $values['nominee_ids'] ?? [];

                return $entry;
            }

            if ($phase->type === WeekPhaseManager::TYPE_VETO) {
                $entry['veto_used'] = $values['veto_used'] ?? null;
                $entry['winner_ids'] = $values['winner_ids'] ?? [];
                $entry['saved_ids'] = $values['saved_ids'] ?? [];
                $entry['replacement_ids'] = $values['replacement_ids'] ?? [];

                return $entry;
            }

            if ($phase->type === WeekPhaseManager::TYPE_EVICTIONS) {
                $entry['evicted_ids'] = $values['evicted_ids'] ?? [];
            }

            return $entry;
        })
        ->all();
}

it('calculates and stores weekly scores when an outcome exists', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 1]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $hoh = Houseguest::factory()->for($season)->create();
    $nominee1 = Houseguest::factory()->for($season)->create();
    $nominee2 = Houseguest::factory()->for($season)->create();
    $vetoWinner = Houseguest::factory()->for($season)->create();
    $saved = Houseguest::factory()->for($season)->create();
    $replacement = Houseguest::factory()->for($season)->create();
    $evicted = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()->submitted()
        ->for($week)
        ->for($user)
        ->create([
            'phase_picks' => scoreWeekPayloadForValues(
                $week,
                [
                    'hoh_ids' => [$hoh->id],
                    'nominee_ids' => [$nominee1->id, $nominee2->id],
                    'veto_used' => true,
                    'winner_ids' => [$vetoWinner->id],
                    'saved_ids' => [$saved->id],
                    'replacement_ids' => [$replacement->id],
                    'evicted_ids' => [$evicted->id],
                ],
            ),
        ]);

    WeekOutcome::factory()->for($week)->create([
        'phase_results' => scoreWeekPayloadForValues(
            $week,
            [
                'hoh_ids' => [$hoh->id],
                'nominee_ids' => [$nominee1->id, $nominee2->id],
                'veto_used' => true,
                'winner_ids' => [$vetoWinner->id],
                'saved_ids' => [$saved->id],
                'replacement_ids' => [$replacement->id],
                'evicted_ids' => [$evicted->id],
            ],
        ),
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(8);
    expect($score->breakdown)
        ->toHaveKeys(['hoh', 'nominees_points', 'veto_winner', 'veto_used', 'saved', 'replacement', 'evicted']);
});

it('does not create scores if a week has no outcome', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 1]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $prediction = Prediction::factory()->submitted()->for($week)->for($user)->create();

    app(ScoreWeek::class)->run($week, $admin);

    expect(PredictionScore::query()->where('prediction_id', $prediction->id)->exists())->toBeFalse();
});

it('scores dynamic nominees and evicted counts using json arrays', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 2]);
    $week->phases()->delete();
    $week->phases()->createMany([
        [
            'position' => 1,
            'type' => WeekPhaseManager::TYPE_HOH,
            'config' => ['hoh_count' => 1],
        ],
        [
            'position' => 2,
            'type' => WeekPhaseManager::TYPE_NOMINEES,
            'config' => ['nominee_count' => 3],
        ],
        [
            'position' => 3,
            'type' => WeekPhaseManager::TYPE_VETO,
            'config' => [
                'winner_count' => 1,
                'saved_count' => 1,
                'replacement_count' => 1,
            ],
        ],
        [
            'position' => 4,
            'type' => WeekPhaseManager::TYPE_EVICTIONS,
            'config' => ['evicted_count' => 2],
        ],
    ]);
    $week->load('phases');

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $hoh = Houseguest::factory()->for($season)->create();
    $nominee1 = Houseguest::factory()->for($season)->create();
    $nominee2 = Houseguest::factory()->for($season)->create();
    $nominee3 = Houseguest::factory()->for($season)->create();
    $vetoWinner = Houseguest::factory()->for($season)->create();
    $saved = Houseguest::factory()->for($season)->create();
    $replacement = Houseguest::factory()->for($season)->create();
    $evicted1 = Houseguest::factory()->for($season)->create();
    $evicted2 = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()->submitted()
        ->for($week)
        ->for($user)
        ->create([
            'phase_picks' => scoreWeekPayloadForValues(
                $week,
                [
                    'hoh_ids' => [$hoh->id],
                    'nominee_ids' => [$nominee1->id, $nominee2->id, $nominee3->id],
                    'veto_used' => true,
                    'winner_ids' => [$vetoWinner->id],
                    'saved_ids' => [$saved->id],
                    'replacement_ids' => [$replacement->id],
                    'evicted_ids' => [$evicted1->id, $evicted2->id],
                ],
            ),
        ]);

    WeekOutcome::factory()->for($week)->create([
        'phase_results' => scoreWeekPayloadForValues(
            $week,
            [
                'hoh_ids' => [$hoh->id],
                'nominee_ids' => [$nominee1->id, $nominee2->id, $nominee3->id],
                'veto_used' => true,
                'winner_ids' => [$vetoWinner->id],
                'saved_ids' => [$saved->id],
                'replacement_ids' => [$replacement->id],
                'evicted_ids' => [$evicted1->id, $evicted2->id],
            ],
        ),
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(10);
    expect($score->breakdown['nominees_points'])->toBe(3);
    expect($score->breakdown['evicted_points'])->toBe(2);
    expect($score->breakdown['evicted'])->toBeNull();
});

it('scores dynamic bosses using json arrays', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 3]);
    $week->phases()
        ->where('type', WeekPhaseManager::TYPE_HOH)
        ->firstOrFail()
        ->forceFill(['config' => ['hoh_count' => 2]])
        ->save();
    $week->load('phases');

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $boss1 = Houseguest::factory()->for($season)->create();
    $boss2 = Houseguest::factory()->for($season)->create();

    $prediction = Prediction::factory()->submitted()
        ->for($week)
        ->for($user)
        ->create([
            'phase_picks' => scoreWeekPayloadForValues(
                $week,
                [
                    'hoh_ids' => [$boss1->id, $boss2->id],
                ],
            ),
        ]);

    WeekOutcome::factory()->for($week)->create([
        'phase_results' => scoreWeekPayloadForValues(
            $week,
            [
                'hoh_ids' => [$boss1->id, $boss2->id],
            ],
        ),
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(2);
    expect($score->breakdown['boss_points'])->toBe(2);
    expect($score->breakdown['hoh'])->toBeNull();
});

it('aggregates repeated veto correctness across all veto phases', function () {
    $season = Season::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($season)->create(['number' => 4]);
    $week->phases()->delete();
    $week->phases()->createMany([
        [
            'position' => 1,
            'type' => WeekPhaseManager::TYPE_HOH,
            'config' => ['hoh_count' => 1],
        ],
        [
            'position' => 2,
            'type' => WeekPhaseManager::TYPE_NOMINEES,
            'config' => ['nominee_count' => 2],
        ],
        [
            'position' => 3,
            'type' => WeekPhaseManager::TYPE_VETO,
            'config' => ['winner_count' => 1, 'saved_count' => 1, 'replacement_count' => 1],
        ],
        [
            'position' => 4,
            'type' => WeekPhaseManager::TYPE_VETO,
            'config' => ['winner_count' => 1, 'saved_count' => 1, 'replacement_count' => 1],
        ],
        [
            'position' => 5,
            'type' => WeekPhaseManager::TYPE_VETO,
            'config' => ['winner_count' => 1, 'saved_count' => 1, 'replacement_count' => 1],
        ],
        [
            'position' => 6,
            'type' => WeekPhaseManager::TYPE_EVICTIONS,
            'config' => ['evicted_count' => 1],
        ],
    ]);
    $week->load('phases');

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $hoh = Houseguest::factory()->for($season)->create();
    $nominee1 = Houseguest::factory()->for($season)->create();
    $nominee2 = Houseguest::factory()->for($season)->create();
    $winner1 = Houseguest::factory()->for($season)->create();
    $winner2 = Houseguest::factory()->for($season)->create();
    $winner3 = Houseguest::factory()->for($season)->create();
    $saved1 = Houseguest::factory()->for($season)->create();
    $saved3 = Houseguest::factory()->for($season)->create();
    $replacement1 = Houseguest::factory()->for($season)->create();
    $replacement3 = Houseguest::factory()->for($season)->create();
    $evicted = Houseguest::factory()->for($season)->create();
    $wrongWinner = Houseguest::factory()->for($season)->create();
    $wrongSaved = Houseguest::factory()->for($season)->create();
    $wrongReplacement = Houseguest::factory()->for($season)->create();

    $phasesByPosition = $week->phases->keyBy('position');

    $prediction = Prediction::factory()->submitted()
        ->for($week)
        ->for($user)
        ->create([
            'phase_picks' => [
                [
                    'phase_id' => $phasesByPosition[1]->id,
                    'position' => 1,
                    'type' => WeekPhaseManager::TYPE_HOH,
                    'hoh_ids' => [$hoh->id],
                ],
                [
                    'phase_id' => $phasesByPosition[2]->id,
                    'position' => 2,
                    'type' => WeekPhaseManager::TYPE_NOMINEES,
                    'nominee_ids' => [$nominee1->id, $nominee2->id],
                ],
                [
                    'phase_id' => $phasesByPosition[3]->id,
                    'position' => 3,
                    'type' => WeekPhaseManager::TYPE_VETO,
                    'veto_used' => true,
                    'winner_ids' => [$winner1->id],
                    'saved_ids' => [$saved1->id],
                    'replacement_ids' => [$replacement1->id],
                ],
                [
                    'phase_id' => $phasesByPosition[4]->id,
                    'position' => 4,
                    'type' => WeekPhaseManager::TYPE_VETO,
                    'veto_used' => false,
                    'winner_ids' => [$wrongWinner->id],
                    'saved_ids' => [],
                    'replacement_ids' => [],
                ],
                [
                    'phase_id' => $phasesByPosition[5]->id,
                    'position' => 5,
                    'type' => WeekPhaseManager::TYPE_VETO,
                    'veto_used' => true,
                    'winner_ids' => [$wrongWinner->id],
                    'saved_ids' => [$wrongSaved->id],
                    'replacement_ids' => [$wrongReplacement->id],
                ],
                [
                    'phase_id' => $phasesByPosition[6]->id,
                    'position' => 6,
                    'type' => WeekPhaseManager::TYPE_EVICTIONS,
                    'evicted_ids' => [$evicted->id],
                ],
            ],
        ]);

    WeekOutcome::factory()->for($week)->create([
        'phase_results' => [
            [
                'phase_id' => $phasesByPosition[1]->id,
                'position' => 1,
                'type' => WeekPhaseManager::TYPE_HOH,
                'hoh_ids' => [$hoh->id],
            ],
            [
                'phase_id' => $phasesByPosition[2]->id,
                'position' => 2,
                'type' => WeekPhaseManager::TYPE_NOMINEES,
                'nominee_ids' => [$nominee1->id, $nominee2->id],
            ],
            [
                'phase_id' => $phasesByPosition[3]->id,
                'position' => 3,
                'type' => WeekPhaseManager::TYPE_VETO,
                'veto_used' => true,
                'winner_ids' => [$winner1->id],
                'saved_ids' => [$saved1->id],
                'replacement_ids' => [$replacement1->id],
            ],
            [
                'phase_id' => $phasesByPosition[4]->id,
                'position' => 4,
                'type' => WeekPhaseManager::TYPE_VETO,
                'veto_used' => true,
                'winner_ids' => [$winner2->id],
                'saved_ids' => [$saved1->id],
                'replacement_ids' => [$replacement1->id],
            ],
            [
                'phase_id' => $phasesByPosition[5]->id,
                'position' => 5,
                'type' => WeekPhaseManager::TYPE_VETO,
                'veto_used' => true,
                'winner_ids' => [$winner3->id],
                'saved_ids' => [$saved3->id],
                'replacement_ids' => [$replacement3->id],
            ],
            [
                'phase_id' => $phasesByPosition[6]->id,
                'position' => 6,
                'type' => WeekPhaseManager::TYPE_EVICTIONS,
                'evicted_ids' => [$evicted->id],
            ],
        ],
        'last_admin_edited_by_user_id' => $admin->id,
        'last_admin_edited_at' => now(),
    ]);

    app(ScoreWeek::class)->run($week, $admin);

    $score = PredictionScore::query()->where('prediction_id', $prediction->id)->first();

    expect($score)->not->toBeNull();
    expect($score->points)->toBe(9);
    expect($score->breakdown['veto_winner'])->toBeFalse();
    expect($score->breakdown['veto_used'])->toBeFalse();
    expect($score->breakdown['saved'])->toBeFalse();
    expect($score->breakdown['replacement'])->toBeFalse();
});
