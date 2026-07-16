<?php

namespace App\Models;

use App\Enums\PredictionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPrediction extends Model
{
    /** @use HasFactory<\Database\Factories\EventPredictionFactory> */
    use HasFactory;

    protected $fillable = ['event_id', 'pool_member_id', 'status', 'submitted_at', 'locked_at'];

    protected $attributes = ['status' => PredictionStatus::Draft->value];

    protected function casts(): array
    {
        return [
            'status' => PredictionStatus::class,
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function selectionRows(): HasMany
    {
        return $this->hasMany(EventPredictionSelection::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(EventOption::class, 'event_prediction_selections')->withTimestamps();
    }
}
