<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointEntry extends Model
{
    /** @use HasFactory<\Database\Factories\PointEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_member_id', 'event_id', 'event_result_id', 'event_prediction_id',
        'reverses_point_entry_id', 'type', 'points', 'reason', 'idempotency_key',
    ];

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

    public function eventPrediction(): BelongsTo
    {
        return $this->belongsTo(EventPrediction::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_point_entry_id');
    }
}
