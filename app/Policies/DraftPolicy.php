<?php

namespace App\Policies;

use App\Enums\DraftStatus;
use App\Models\Draft;
use App\Models\User;

class DraftPolicy
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
    public function view(User $user, Draft $draft): bool
    {
        return $user->is_admin || $draft->pool->members()->whereBelongsTo($user)->where('status', 'active')->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Draft $draft): bool
    {
        return $user->is_admin || $draft->pool->isManagedBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Draft $draft): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Draft $draft): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Draft $draft): bool
    {
        return false;
    }

    public function pick(User $user, Draft $draft): bool
    {
        if ($draft->status !== DraftStatus::Active) {
            return false;
        }

        return $draft->currentMember?->user_id === $user->id;
    }
}
