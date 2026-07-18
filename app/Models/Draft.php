<?php

namespace App\Models;

use App\Enums\DraftStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Draft extends Model
{
    /** @use HasFactory<\Database\Factories\DraftFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'status', 'current_pick_number', 'current_pool_member_id', 'pick_seconds',
        'turn_started_at', 'started_at', 'completed_at',
    ];

    protected $attributes = [
        'status' => DraftStatus::Pending->value,
        'current_pick_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DraftStatus::class,
            'turn_started_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function currentMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class, 'current_pool_member_id');
    }

    public function picks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $draft): void {
            $poolIds = collect([$draft->pool_id, $draft->getRawOriginal('pool_id')])
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
