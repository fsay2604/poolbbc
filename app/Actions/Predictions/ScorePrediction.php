<?php

namespace App\Actions\Predictions;

use App\Actions\Weeks\WeekPhaseManager;
use App\Models\Prediction;
use App\Models\WeekOutcome;

class ScorePrediction
{
    public function __construct(public WeekPhaseManager $weekPhaseManager) {}

    /**
     * @return array{points:int, breakdown:array<string, mixed>}
     */
    public function score(Prediction $prediction, WeekOutcome $outcome): array
    {
        $prediction->week->loadMissing('phases');
        $phases = $prediction->week->phases;

        $predictedPayload = is_array($prediction->phase_picks) && $prediction->phase_picks !== []
            ? $prediction->phase_picks
            : $this->weekPhaseManager->legacyPayloadForModel($prediction, $phases);
        $actualPayload = is_array($outcome->phase_results) && $outcome->phase_results !== []
            ? $outcome->phase_results
            : $this->weekPhaseManager->legacyPayloadForModel($outcome, $phases);

        $predictedByPhaseId = $this->weekPhaseManager->payloadByPhaseId($predictedPayload);
        $actualByPhaseId = $this->weekPhaseManager->payloadByPhaseId($actualPayload);

        $points = 0;
        $phaseScores = [];
        $bossPoints = 0;
        $nomineesPoints = 0;
        $evictedPoints = 0;
        $hohCorrect = null;
        $vetoWinnerCorrect = null;
        $vetoUsedCorrect = null;
        $savedCorrect = null;
        $replacementCorrect = null;
        $evictedCorrect = null;

        foreach ($phases as $phase) {
            $predictedEntry = $predictedByPhaseId[$phase->id] ?? null;
            $actualEntry = $actualByPhaseId[$phase->id] ?? null;
            $predictedVetoUsed = $phase->type === WeekPhaseManager::TYPE_VETO
                ? $this->weekPhaseManager->vetoUsage(is_array($predictedEntry) ? $predictedEntry : null)
                : null;
            $actualVetoUsed = $phase->type === WeekPhaseManager::TYPE_VETO
                ? $this->weekPhaseManager->vetoUsage(is_array($actualEntry) ? $actualEntry : null)
                : null;

            $details = [];
            $subtotal = 0;

            foreach ($this->weekPhaseManager->selectionListKeys($phase->type) as $listKey => $_countKey) {
                $isVetoDependentList = $phase->type === WeekPhaseManager::TYPE_VETO
                    && $this->weekPhaseManager->isVetoDependentListKey($listKey);

                if ($isVetoDependentList && ! ($predictedVetoUsed === true && $actualVetoUsed === true)) {
                    $details[$listKey] = 0;

                    continue;
                }

                $predictedIds = $this->weekPhaseManager->normalizeIdList($predictedEntry[$listKey] ?? []);
                $actualIds = $this->weekPhaseManager->normalizeIdList($actualEntry[$listKey] ?? []);
                $listPoints = count(array_intersect($predictedIds, $actualIds));

                $details[$listKey] = $listPoints;
                $subtotal += $listPoints;

                if ($phase->type === WeekPhaseManager::TYPE_HOH && $listKey === 'hoh_ids') {
                    $bossPoints += $listPoints;

                    if (count($predictedIds) === 1 && count($actualIds) === 1) {
                        $hohCorrect = $predictedIds[0] === $actualIds[0];
                    }
                }

                if ($phase->type === WeekPhaseManager::TYPE_NOMINEES && $listKey === 'nominee_ids') {
                    $nomineesPoints += $listPoints;
                }

                if ($phase->type === WeekPhaseManager::TYPE_VETO && $listKey === 'winner_ids' && $vetoWinnerCorrect === null) {
                    if (count($predictedIds) === 1 && count($actualIds) === 1) {
                        $vetoWinnerCorrect = $predictedIds[0] === $actualIds[0];
                    }
                }

                if ($phase->type === WeekPhaseManager::TYPE_VETO && $listKey === 'saved_ids' && $savedCorrect === null) {
                    if (count($predictedIds) <= 1 && count($actualIds) <= 1) {
                        $savedCorrect = $predictedIds === $actualIds;
                    }
                }

                if ($phase->type === WeekPhaseManager::TYPE_VETO && $listKey === 'replacement_ids' && $replacementCorrect === null) {
                    if (count($predictedIds) <= 1 && count($actualIds) <= 1) {
                        $replacementCorrect = $predictedIds === $actualIds;
                    }
                }

                if ($phase->type === WeekPhaseManager::TYPE_EVICTIONS && $listKey === 'evicted_ids') {
                    $evictedPoints += $listPoints;

                    if (count($predictedIds) === 1 && count($actualIds) === 1) {
                        $evictedCorrect = $predictedIds[0] === $actualIds[0];
                    }
                }
            }

            if ($phase->type === WeekPhaseManager::TYPE_VETO) {
                if ($actualVetoUsed !== null) {
                    $vetoUsedPoints = $predictedVetoUsed !== null && $predictedVetoUsed === $actualVetoUsed ? 1 : 0;
                    $details['veto_used'] = $vetoUsedPoints;
                    $subtotal += $vetoUsedPoints;

                    if ($vetoUsedCorrect === null) {
                        $vetoUsedCorrect = $predictedVetoUsed !== null && $predictedVetoUsed === $actualVetoUsed;
                    }
                }
            }

            $points += $subtotal;

            $phaseScores[] = [
                'phase_id' => $phase->id,
                'position' => $phase->position,
                'type' => $phase->type,
                'subtotal' => $subtotal,
                'details' => $details,
            ];
        }

        return [
            'points' => $points,
            'breakdown' => [
                'week_total' => $points,
                'phase_scores' => $phaseScores,
                'hoh' => $hohCorrect,
                'boss_points' => $bossPoints,
                'nominees_points' => $nomineesPoints,
                'veto_winner' => $vetoWinnerCorrect,
                'veto_used' => $vetoUsedCorrect,
                'saved' => $savedCorrect,
                'replacement' => $replacementCorrect,
                'evicted' => $evictedCorrect,
                'evicted_points' => $evictedPoints,
            ],
        ];
    }
}
