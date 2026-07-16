<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAuditLog
{
    /** @param array<string, mixed> $metadata */
    public function handle(?Pool $pool, User $user, string $action, Model $auditable, array $metadata = []): AuditLog
    {
        return AuditLog::query()->create([
            'pool_id' => $pool?->id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
