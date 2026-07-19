<?php

namespace App\Models;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\ResultPublicationMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SeasonEvent extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonEventFactory> */
    use HasFactory;

    protected $fillable = [
        'season_round_id', 'event_type_id', 'created_by', 'name', 'question', 'answer_source',
        'include_inactive_houseguests', 'allow_none', 'scoring_config', 'default_mode',
        'status', 'position', 'opens_at', 'locks_at', 'prediction_min_selections',
        'prediction_max_selections', 'result_min_selections', 'result_max_selections', 'result_publication_mode', 'options_locked_at', 'cancelled_at',
    ];

    protected $attributes = [
        'answer_source' => AnswerSource::Houseguests->value,
        'status' => EventStatus::Draft->value,
        'prediction_min_selections' => 1,
        'prediction_max_selections' => 1,
        'result_min_selections' => 1,
        'result_max_selections' => 1,
        'scoring_config' => '{}',
        'default_mode' => EventMode::Hybrid->value,
        'result_publication_mode' => ResultPublicationMode::Immediate->value,
    ];

    protected function casts(): array
    {
        return [
            'answer_source' => AnswerSource::class,
            'include_inactive_houseguests' => 'boolean',
            'allow_none' => 'boolean',
            'scoring_config' => 'array',
            'default_mode' => EventMode::class,
            'result_publication_mode' => ResultPublicationMode::class,
            'status' => EventStatus::class,
            'opens_at' => 'datetime',
            'locks_at' => 'datetime',
            'options_locked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(SeasonRound::class, 'season_round_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(SeasonEventOption::class)->orderBy('position');
    }

    public function results(): HasMany
    {
        return $this->hasMany(SeasonEventResult::class)->orderByDesc('version');
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(SeasonEventResult::class)
            ->ofMany(['version' => 'max'], fn ($query) => $query
                ->where('status', 'published')
                ->whereNotNull('published_at'));
    }

    public function draftResult(): HasOne
    {
        return $this->hasOne(SeasonEventResult::class)
            ->ofMany(['version' => 'max'], fn ($query) => $query->where('status', 'draft'));
    }

    public function poolEvents(): HasMany
    {
        return $this->hasMany(PoolEvent::class);
    }

    public function effectiveStatus(?Carbon $now = null): EventStatus
    {
        $now ??= now();

        if (in_array($this->status, [EventStatus::ResultEntered, EventStatus::Published, EventStatus::Cancelled], true)) {
            return $this->status;
        }

        if ($this->opens_at !== null && $this->opens_at->isAfter($now)) {
            return EventStatus::Draft;
        }

        if ($this->locks_at !== null && $this->locks_at->lessThanOrEqualTo($now)) {
            return EventStatus::Locked;
        }

        return $this->status === EventStatus::Draft && $this->opens_at?->lessThanOrEqualTo($now)
            ? EventStatus::Open
            : $this->status;
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if ($event->isDirty('options_locked_at') && $event->getRawOriginal('options_locked_at') !== null) {
                throw ValidationException::withMessages([
                    'event' => __('Official options cannot be unlocked after their snapshot is frozen.'),
                ]);
            }

            if ($event->isDirty([
                'season_round_id', 'event_type_id', 'name', 'question', 'answer_source', 'position',
                'include_inactive_houseguests', 'allow_none',
                'scoring_config', 'default_mode',
                'opens_at', 'locks_at', 'prediction_min_selections', 'prediction_max_selections',
                'result_min_selections', 'result_max_selections', 'result_publication_mode',
            ])
                && ($event->options_locked_at !== null
                    || EventStatus::from($event->getRawOriginal('status')) !== EventStatus::Draft
                    || $event->poolEvents()->whereHas('predictions')->exists())) {
                throw ValidationException::withMessages([
                    'event' => __('Official event rules cannot change after opening or the first response.'),
                ]);
            }
        });
    }
}
