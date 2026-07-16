<?php

namespace App\Models;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'round_id', 'event_type_id', 'created_by', 'name', 'question', 'mode',
        'answer_source', 'status', 'position', 'opens_at', 'locks_at',
        'prediction_min_selections', 'prediction_max_selections', 'result_min_selections',
        'result_max_selections', 'scoring_config', 'published_at', 'cancelled_at',
    ];

    protected $attributes = [
        'mode' => EventMode::Prediction->value,
        'answer_source' => AnswerSource::Houseguests->value,
        'status' => EventStatus::Draft->value,
        'position' => 1,
        'prediction_min_selections' => 1,
        'prediction_max_selections' => 1,
        'result_min_selections' => 1,
        'result_max_selections' => 1,
        'scoring_config' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'mode' => EventMode::class,
            'answer_source' => AnswerSource::class,
            'status' => EventStatus::class,
            'opens_at' => 'datetime',
            'locks_at' => 'datetime',
            'scoring_config' => 'array',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(EventOption::class)->orderBy('position');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(EventPrediction::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(EventResult::class)->orderByDesc('version');
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(EventResult::class)->ofMany('version', 'max');
    }

    public function isPredictionOpen(): bool
    {
        return $this->status === EventStatus::Open
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->locks_at === null || $this->locks_at->isFuture());
    }
}
