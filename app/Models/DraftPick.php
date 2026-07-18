<?php

namespace App\Models;

use App\Enums\DraftStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DraftPick extends Model
{
    /** @use HasFactory<\Database\Factories\DraftPickFactory> */
    use HasFactory;

    protected $fillable = [
        'draft_id', 'pool_id', 'pool_member_id', 'houseguest_id', 'round_number',
        'pick_number', 'exclusive_claim', 'picked_at',
    ];

    protected function casts(): array
    {
        return ['picked_at' => 'datetime'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function houseguest(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class);
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $pick): void {
            $poolIds = collect([$pick->pool_id, $pick->getRawOriginal('pool_id')])
                ->filter()
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        };

        $guardCompletedDraft = function (self $pick): void {
            $status = $pick->draft()->value('status');

            if (in_array($status, [DraftStatus::Completed, DraftStatus::Completed->value], true)) {
                throw ValidationException::withMessages([
                    'draft' => __('A completed draft is immutable.'),
                ]);
            }
        };

        static::creating($rejectReadOnlyPoolMutation);
        static::updating($rejectReadOnlyPoolMutation);
        static::deleting($rejectReadOnlyPoolMutation);
        static::updating($guardCompletedDraft);
        static::deleting($guardCompletedDraft);
    }
}
