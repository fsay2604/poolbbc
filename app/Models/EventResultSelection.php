<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventResultSelection extends Model
{
    /** @use HasFactory<\Database\Factories\EventResultSelectionFactory> */
    use HasFactory;

    protected $fillable = ['event_result_id', 'event_option_id'];

    public function result(): BelongsTo
    {
        return $this->belongsTo(EventResult::class, 'event_result_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventOption::class, 'event_option_id');
    }
}
