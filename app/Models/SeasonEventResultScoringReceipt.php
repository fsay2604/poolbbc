<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonEventResultScoringReceipt extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonEventResultScoringReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'season_event_result_id', 'pool_event_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(SeasonEventResult::class, 'season_event_result_id');
    }

    public function poolEvent(): BelongsTo
    {
        return $this->belongsTo(PoolEvent::class);
    }
}
