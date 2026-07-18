<?php

namespace App\Policies;

use App\Models\Prediction;
use App\Models\User;

class PredictionPolicy
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
    public function view(User $user, Prediction $prediction): bool
    {
        if ($prediction->user_id === $user->id) {
            return true;
        }

        return $prediction->confirmed_at !== null && $prediction->week->isLocked();
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
    public function update(User $user, Prediction $prediction): bool
    {
        if ($prediction->user_id === $user->id) {
            return ! $prediction->week->isLocked();
        }

        return $user->is_admin && $prediction->confirmed_at !== null && $prediction->week->isLocked();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Prediction $prediction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Prediction $prediction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Prediction $prediction): bool
    {
        return false;
    }
}
