<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolInvitation extends Model
{
    /** @use HasFactory<\Database\Factories\PoolInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'email', 'code', 'status', 'accepted_by', 'expires_at', 'accepted_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $invitation): void {
            $poolIds = collect([$invitation->pool_id, $invitation->getRawOriginal('pool_id')])
                ->filter()
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        };

        static::creating($rejectReadOnlyPoolMutation);
        static::updating($rejectReadOnlyPoolMutation);
        static::deleting($rejectReadOnlyPoolMutation);
    }
}
