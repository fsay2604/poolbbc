<?php

namespace App\Policies;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolStatus;
use App\Models\Event;
use App\Models\PointEntry;
use App\Models\PoolEvent;
use App\Models\User;

class EventPolicy
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
    public function view(User $user, Event $event): bool
    {
        return $event->pool->members()->whereBelongsTo($user)->where('status', 'active')->exists();
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
    public function update(User $user, Event $event): bool
    {
        return ! in_array($event->pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)
            && $event->pool->isManagedBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event)
            && $event->status === EventStatus::Draft
            && $event->effectiveStatus() === EventStatus::Draft
            && ! $event->predictions()->exists()
            && ! $event->results()->exists()
            && ! PointEntry::query()->whereBelongsTo($event)->exists()
            && ! PoolEvent::query()->whereBelongsTo($event, 'localEvent')->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Event $event): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Event $event): bool
    {
        return false;
    }

    public function predict(User $user, Event $event): bool
    {
        return in_array($event->mode, [EventMode::Prediction, EventMode::Hybrid], true)
            && $event->isPredictionOpen()
            && ! in_array($event->pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)
            && $event->pool->members()->whereBelongsTo($user)->where('status', 'active')->exists();
    }

    public function publishResult(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    public function recordResult(User $user, Event $event): bool
    {
        return $this->publishResult($user, $event);
    }
}
