<?php

namespace App\Policies;

use App\Models\SeasonEventResult;
use App\Models\User;

class SeasonEventResultPolicy
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
    public function view(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return $user->is_admin || $seasonEventResult->status === 'published';
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
    public function update(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return false;
    }

    public function publish(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return $user->is_admin && $seasonEventResult->status === 'draft';
    }

    public function amend(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return $user->is_admin && $seasonEventResult->status === 'draft';
    }

    public function retry(User $user, SeasonEventResult $seasonEventResult): bool
    {
        return $user->is_admin && in_array($seasonEventResult->status, ['pending', 'failed'], true);
    }
}
