<?php

namespace App\Policies;

use App\Models\Pool;
use App\Models\User;

class PoolPolicy
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
    public function view(User $user, Pool $pool): bool
    {
        return $user->is_admin || $pool->members()->whereBelongsTo($user)->where('status', 'active')->exists();
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
    public function update(User $user, Pool $pool): bool
    {
        return $user->is_admin || $pool->isManagedBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pool $pool): bool
    {
        return $user->is_admin || $pool->owner_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Pool $pool): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Pool $pool): bool
    {
        return false;
    }

    public function manage(User $user, Pool $pool): bool
    {
        return $this->update($user, $pool);
    }
}
