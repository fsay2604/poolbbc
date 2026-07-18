<?php

namespace App\Policies;

use App\Models\SeasonPrediction;
use App\Models\User;

class SeasonPredictionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SeasonPrediction $seasonPrediction): bool
    {
        if ($seasonPrediction->user_id === $user->id) {
            return true;
        }

        return $seasonPrediction->confirmed_at !== null
            && $seasonPrediction->season->predictionsAreLocked();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SeasonPrediction $seasonPrediction): bool
    {
        return $seasonPrediction->user_id === $user->id
            && $seasonPrediction->season->predictionsAreOpen();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SeasonPrediction $seasonPrediction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SeasonPrediction $seasonPrediction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SeasonPrediction $seasonPrediction): bool
    {
        return false;
    }
}
