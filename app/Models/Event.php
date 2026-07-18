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

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'round_id', 'event_type_id', 'created_by', 'name', 'question', 'mode',
        'answer_source', 'status', 'position', 'opens_at', 'locks_at',
        'prediction_min_selections', 'prediction_max_selections', 'result_min_selections',
        'result_max_selections', 'scoring_config', 'result_publication_mode', 'published_at', 'cancelled_at',
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
        'result_publication_mode' => ResultPublicationMode::Immediate->value,
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
            'result_publication_mode' => ResultPublicationMode::class,
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
        return $this->hasOne(EventResult::class)
            ->ofMany(['version' => 'max'], fn ($query) => $query
                ->where('status', 'published')
                ->whereNotNull('published_at'));
    }

    public function draftResult(): HasOne
    {
        return $this->hasOne(EventResult::class)
            ->ofMany(['version' => 'max'], fn ($query) => $query->where('status', 'draft'));
    }

    public function isPredictionOpen(): bool
    {
        return $this->effectiveStatus() === EventStatus::Open;
    }

    public function effectiveStatus(?Carbon $now = null): EventStatus
    {
        $now ??= now();

        if (in_array($this->status, [EventStatus::ResultEntered, EventStatus::Published, EventStatus::Cancelled], true)) {
            return $this->status;
        }

        $hasOpened = $this->opens_at === null || $this->opens_at->lessThanOrEqualTo($now);
        if (! $hasOpened) {
            return EventStatus::Draft;
        }
        if ($hasOpened && $this->locks_at !== null && $this->locks_at->lessThanOrEqualTo($now)) {
            return EventStatus::Locked;
        }

        if ($this->status === EventStatus::Draft && $this->opens_at !== null && $hasOpened) {
            return EventStatus::Open;
        }

        return $this->status;
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $event): void {
            $poolIds = collect([$event->pool_id, $event->getRawOriginal('pool_id')])
                ->filter()
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        };

        static::creating($rejectReadOnlyPoolMutation);
        static::updating($rejectReadOnlyPoolMutation);
        static::deleting($rejectReadOnlyPoolMutation);

        static::updating(function (self $event): void {
            $definitionAttributes = [
                'pool_id', 'round_id', 'event_type_id', 'name', 'question', 'mode', 'answer_source', 'position',
                'opens_at', 'locks_at',
                'prediction_min_selections', 'prediction_max_selections',
                'result_min_selections', 'result_max_selections', 'scoring_config', 'result_publication_mode',
            ];

            if ($event->isDirty($definitionAttributes)
                && ($event->predictions()->exists()
                    || EventStatus::from($event->getRawOriginal('status')) !== EventStatus::Draft
                    || $event->results()->exists())) {
                throw ValidationException::withMessages([
                    'event' => __('Event rules cannot be changed after the first response.'),
                ]);
            }
        });
    }
}
