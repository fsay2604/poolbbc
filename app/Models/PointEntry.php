<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PointEntry extends Model
{
    /** @use HasFactory<\Database\Factories\PointEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_member_id', 'event_id', 'pool_event_id', 'event_result_id', 'season_event_result_id',
        'event_prediction_id', 'pool_event_prediction_id',
        'reverses_point_entry_id', 'type', 'points', 'reason', 'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Point entries are append-only; add a reversal entry instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Point entries are append-only and cannot be removed.');
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $visibilityQuery): void {
            $visibilityQuery
                ->where(function (Builder $historicalQuery): void {
                    $historicalQuery
                        ->whereNull($this->qualifyColumn('event_result_id'))
                        ->whereNull($this->qualifyColumn('season_event_result_id'));
                })
                ->orWhereHas('eventResult', fn (Builder $resultQuery): Builder => $resultQuery
                    ->where('status', 'published')
                    ->whereNotNull('published_at'))
                ->orWhereHas('seasonEventResult', fn (Builder $resultQuery): Builder => $resultQuery
                    ->where('status', 'published')
                    ->whereNotNull('published_at'));
        });
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventResult(): BelongsTo
    {
        return $this->belongsTo(EventResult::class);
    }

    public function poolEvent(): BelongsTo
    {
        return $this->belongsTo(PoolEvent::class);
    }

    public function seasonEventResult(): BelongsTo
    {
        return $this->belongsTo(SeasonEventResult::class);
    }

    public function poolEventPrediction(): BelongsTo
    {
        return $this->belongsTo(PoolEventPrediction::class);
    }

    public function eventPrediction(): BelongsTo
    {
        return $this->belongsTo(EventPrediction::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_point_entry_id');
    }
}
