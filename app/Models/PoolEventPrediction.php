<?php

namespace App\Models;

use App\Enums\PredictionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PoolEventPrediction extends Model
{
    /** @use HasFactory<\Database\Factories\PoolEventPredictionFactory> */
    use HasFactory;

    protected $fillable = ['pool_event_id', 'pool_member_id', 'status', 'submitted_at', 'locked_at'];

    protected $attributes = ['status' => PredictionStatus::Draft->value];

    protected function casts(): array
    {
        return [
            'status' => PredictionStatus::class,
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function poolEvent(): BelongsTo
    {
        return $this->belongsTo(PoolEvent::class);
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(SeasonEventOption::class, 'pool_event_prediction_selections')->withTimestamps();
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $prediction): void {
            $poolEventIds = collect([$prediction->pool_event_id, $prediction->getRawOriginal('pool_event_id')])
                ->filter()
                ->unique()
                ->all();
            $poolIds = PoolEvent::query()
                ->whereKey($poolEventIds)
                ->pluck('pool_id')
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
