<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionPoolStatus
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, User $manager, PoolStatus $target): Pool
    {
        return DB::transaction(function () use ($pool, $manager, $target): Pool {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);

            if (! $pool->isManagedBy($manager)) {
                throw new AuthorizationException(__('You cannot change this pool status.'));
            }

            $from = $pool->status;
            $isAllowed = ($from === PoolStatus::Active && $target === PoolStatus::Completed)
                || ($from === PoolStatus::Completed && $target === PoolStatus::Archived);

            if (! $isAllowed) {
                throw ValidationException::withMessages([
                    'pool' => __('This pool status transition is not allowed.'),
                ]);
            }

            if (in_array($target, [PoolStatus::Completed, PoolStatus::Archived], true)
                && $this->hasUnresolvedEvents($pool)) {
                throw ValidationException::withMessages([
                    'pool' => __('Publish or cancel every event before completing the pool.'),
                ]);
            }

            $pool->update(['status' => $target]);
            $this->recordAuditLog->handle($pool, $manager, 'pool.status_changed', $pool, [
                'from' => $from->value,
                'to' => $target->value,
                'before' => ['status' => $from->value],
                'after' => ['status' => $pool->status->value],
            ]);

            return $pool->fresh(['draft', 'members.user']);
        }, attempts: 3);
    }

    private function hasUnresolvedEvents(Pool $pool): bool
    {
        $terminalStatuses = [EventStatus::Published->value, EventStatus::Cancelled->value];

        return $pool->events()->whereNotIn('status', $terminalStatuses)->exists()
            || $pool->poolEvents()
                ->where('is_active', true)
                ->whereHas('seasonEvent', fn ($query) => $query->whereNotIn('status', $terminalStatuses))
                ->exists();
    }
}
