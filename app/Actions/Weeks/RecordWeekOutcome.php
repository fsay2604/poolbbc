<?php

namespace App\Actions\Weeks;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
use App\Actions\Predictions\RecalculateAllScores;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use App\Support\LegacyFlowAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordWeekOutcome
{
    public function __construct(
        private RebuildSeasonHouseguestActivity $rebuildSeasonHouseguestActivity,
        private RecalculateAllScores $recalculateAllScores,
        private RecordAuditLog $recordAuditLog,
        private LegacyFlowAuthority $legacyFlowAuthority,
    ) {}

    /** @param array<int, array<string, mixed>> $payload */
    public function handle(Week $week, User $administrator, array $payload, ?string $correctionReason = null): WeekOutcome
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages(['outcome' => __('The legacy result flow is read-only after cutover.')]);
        }

        return DB::transaction(function () use ($week, $administrator, $payload, $correctionReason): WeekOutcome {
            $week = Week::query()->with('season')->lockForUpdate()->findOrFail($week->id);
            $outcome = WeekOutcome::query()->whereBelongsTo($week)->lockForUpdate()->first();
            $before = $outcome?->phase_results;
            $isCorrection = $outcome !== null && $before !== $payload;

            if ($isCorrection && blank($correctionReason)) {
                throw ValidationException::withMessages([
                    'correctionReason' => __('A correction reason is required.'),
                ]);
            }

            $outcome ??= new WeekOutcome(['week_id' => $week->id]);
            $outcome->fill([
                'phase_results' => $payload,
                'last_admin_edited_by_user_id' => $administrator->id,
                'last_admin_edited_at' => now(),
            ])->save();

            $this->rebuildSeasonHouseguestActivity->handle($week->season);
            $this->recalculateAllScores->run($week->season->refresh(), $administrator);

            $this->recordAuditLog->handle(null, $administrator, $isCorrection ? 'week.outcome_corrected' : 'week.outcome_recorded', $outcome, [
                'before' => $before,
                'after' => $payload,
                'correction_reason' => $correctionReason,
            ]);

            return $outcome->fresh();
        }, attempts: 3);
    }
}
