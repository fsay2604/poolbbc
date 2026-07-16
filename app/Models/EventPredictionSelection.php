<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPredictionSelection extends Model
{
    /** @use HasFactory<\Database\Factories\EventPredictionSelectionFactory> */
    use HasFactory;

    protected $fillable = ['event_prediction_id', 'event_option_id'];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(EventPrediction::class, 'event_prediction_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventOption::class, 'event_option_id');
    }
}
