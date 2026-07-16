<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventOption extends Model
{
    /** @use HasFactory<\Database\Factories\EventOptionFactory> */
    use HasFactory;

    protected $fillable = ['event_id', 'houseguest_id', 'label', 'value', 'position', 'is_none'];

    protected $attributes = ['position' => 1, 'is_none' => false];

    protected function casts(): array
    {
        return ['is_none' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function houseguest(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class);
    }
}
