<?php

namespace App\Actions\Predictions;

use App\Models\Prediction;
use App\Models\User;
use App\Models\Week;
use App\Support\LegacyFlowAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreWeekPrediction
{
    public function __construct(private LegacyFlowAuthority $legacyFlowAuthority) {}

    /** @param array<int, array<string, mixed>> $payload */
    public function handle(Week $week, User $user, array $payload, bool $submit): Prediction
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages(['prediction' => __('The legacy prediction flow is read-only after cutover.')]);
        }

        return DB::transaction(function () use ($week, $user, $payload, $submit): Prediction {
            $week = Week::query()->lockForUpdate()->findOrFail($week->id);

            if ($week->isLocked()) {
                throw ValidationException::withMessages(['prediction' => __('Predictions are closed for this week.')]);
            }

            $prediction = Prediction::query()->firstOrNew([
                'week_id' => $week->id,
                'user_id' => $user->id,
            ]);
            $prediction->phase_picks = $payload;

            if ($submit) {
                $prediction->confirm();
            }

            $prediction->save();

            return $prediction->fresh();
        }, attempts: 3);
    }
}
