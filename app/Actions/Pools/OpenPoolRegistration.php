<?php

namespace App\Actions\Pools;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenPoolRegistration
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Pool $pool, User $administrator): Pool
    {
        return DB::transaction(function () use ($pool, $administrator): Pool {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);

            if (! $pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot open registrations for this pool.'));
            }

            if ($pool->status !== PoolStatus::Configuration) {
                throw ValidationException::withMessages([
                    'pool' => __('Only a pool in configuration can open registrations.'),
                ]);
            }

            $before = [
                'status' => $pool->status->value,
                'registrations_closed_at' => $pool->registrations_closed_at?->toISOString(),
            ];
            $pool->update([
                'status' => PoolStatus::Registration,
                'registrations_closed_at' => null,
            ]);
            $this->recordAuditLog->handle($pool, $administrator, 'pool.registrations_opened', $pool, [
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
