<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivatePool
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, User $administrator): Pool
    {
        return DB::transaction(function () use ($pool, $administrator): Pool {
            $pool = Pool::query()->with('draft')->lockForUpdate()->findOrFail($pool->id);

            if (! $pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot activate this pool.'));
            }

            if ($pool->status !== PoolStatus::Registration) {
                throw ValidationException::withMessages(['pool' => __('Only a pool in registration can be activated.')]);
            }

            if ($pool->usesDraft() && $pool->draft?->status !== DraftStatus::Completed) {
                throw ValidationException::withMessages(['pool' => __('Complete the draft before activating this pool.')]);
            }

            $before = [
                'status' => $pool->status->value,
                'registrations_closed_at' => $pool->registrations_closed_at?->toISOString(),
            ];
            $pool->update(['status' => PoolStatus::Active, 'registrations_closed_at' => now()]);
            $this->recordAuditLog->handle($pool, $administrator, 'pool.activated', $pool, [
                'competition_mode' => $pool->competition_mode->value,
                'before' => $before,
                'after' => [
                    'status' => $pool->status->value,
                    'registrations_closed_at' => $pool->registrations_closed_at?->toISOString(),
                ],
            ]);

            return $pool->fresh(['draft', 'members.user']);
        }, attempts: 3);
    }
}
