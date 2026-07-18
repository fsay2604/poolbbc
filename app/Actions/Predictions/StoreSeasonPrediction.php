<?php

namespace App\Actions\Predictions;

use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Support\LegacyFlowAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreSeasonPrediction
{
    public function __construct(private LegacyFlowAuthority $legacyFlowAuthority) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Season $season, User $user, array $attributes, bool $submit): SeasonPrediction
    {
        if ($this->legacyFlowAuthority->cutoverEnabled()) {
            throw ValidationException::withMessages(['prediction' => __('The legacy prediction flow is read-only after cutover.')]);
        }

        return DB::transaction(function () use ($season, $user, $attributes, $submit): SeasonPrediction {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);

            if (! $season->predictionsAreOpen()) {
                throw ValidationException::withMessages(['prediction' => __('Season predictions are closed.')]);
            }

            $prediction = SeasonPrediction::query()->firstOrNew([
                'season_id' => $season->id,
                'user_id' => $user->id,
            ]);
            $prediction->fill($attributes);

            if ($submit) {
                $prediction->confirm();
            }

            $prediction->save();

            return $prediction->fresh();
        }, attempts: 3);
    }
}
