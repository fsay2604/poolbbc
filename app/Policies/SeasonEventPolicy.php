<?php

namespace App\Policies;

use App\Models\SeasonEvent;
use App\Models\User;

class SeasonEventPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SeasonEvent $seasonEvent): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SeasonEvent $seasonEvent): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SeasonEvent $seasonEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SeasonEvent $seasonEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SeasonEvent $seasonEvent): bool
    {
        return false;
    }

    public function transition(User $user, SeasonEvent $seasonEvent): bool
    {
        return $this->update($user, $seasonEvent);
    }

    public function recordResult(User $user, SeasonEvent $seasonEvent): bool
    {
        return $this->update($user, $seasonEvent);
    }

    public function publishResult(User $user, SeasonEvent $seasonEvent): bool
    {
        return $this->update($user, $seasonEvent);
    }
}
