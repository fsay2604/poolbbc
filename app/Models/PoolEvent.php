<?php

namespace App\Models;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PoolEvent extends Model
{
    /** @use HasFactory<\Database\Factories\PoolEventFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'season_event_id', 'local_event_id', 'mode', 'is_active', 'visibility',
        'prediction_min_selections', 'prediction_max_selections', 'scoring_config', 'rules_customized_at',
    ];

    protected $attributes = [
        'mode' => EventMode::Prediction->value,
        'is_active' => true,
        'visibility' => 'after_lock',
        'prediction_min_selections' => 1,
        'prediction_max_selections' => 1,
        'scoring_config' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'mode' => EventMode::class,
            'is_active' => 'boolean',
            'scoring_config' => 'array',
            'rules_customized_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function seasonEvent(): BelongsTo
    {
        return $this->belongsTo(SeasonEvent::class);
    }

    public function localEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'local_event_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(PoolEventPrediction::class);
    }

    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }

    public function resultScoringReceipts(): HasMany
    {
        return $this->hasMany(SeasonEventResultScoringReceipt::class);
    }

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $poolEvent): void {
            $poolIds = collect([$poolEvent->pool_id, $poolEvent->getRawOriginal('pool_id')])
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

        static::saving(function (self $poolEvent): void {
            if (($poolEvent->season_event_id === null) === ($poolEvent->local_event_id === null)) {
                throw ValidationException::withMessages([
                    'event' => __('A pool event must reference exactly one official or local event.'),
                ]);
            }

            $pool = $poolEvent->relationLoaded('pool')
                ? $poolEvent->pool
                : Pool::query()->find($poolEvent->pool_id);
            if ($pool !== null && ! $pool->supportsEventMode($poolEvent->mode)) {
                throw ValidationException::withMessages([
                    'mode' => __('This event mode is incompatible with the pool competition mode.'),
                ]);
            }

            if ($poolEvent->prediction_min_selections < 0
                || $poolEvent->prediction_max_selections < 1
                || $poolEvent->prediction_max_selections < $poolEvent->prediction_min_selections) {
                throw ValidationException::withMessages([
                    'event' => __('The prediction selection limits are invalid.'),
                ]);
            }

            if ($pool !== null && $poolEvent->season_event_id !== null) {
                $seasonId = SeasonEvent::query()
                    ->whereKey($poolEvent->season_event_id)
                    ->whereHas('round', fn ($query) => $query->where('season_id', $pool->season_id))
                    ->exists();
                if (! $seasonId) {
                    throw ValidationException::withMessages([
                        'event' => __('The official event must belong to the pool season.'),
                    ]);
                }
            }

            if ($poolEvent->exists
                && $poolEvent->isDirty(['mode', 'visibility', 'prediction_min_selections', 'prediction_max_selections', 'scoring_config'])
                && ($poolEvent->predictions()->exists()
                    || $poolEvent->pointEntries()->exists()
                    || ($poolEvent->season_event_id !== null && SeasonEvent::query()
                        ->whereKey($poolEvent->season_event_id)
                        ->whereIn('status', [
                            EventStatus::Locked->value,
                            EventStatus::ResultEntered->value,
                            EventStatus::Published->value,
                            EventStatus::Cancelled->value,
                        ])
                        ->exists())
                    || ($poolEvent->local_event_id !== null && Event::query()
                        ->whereKey($poolEvent->local_event_id)
                        ->where('status', '!=', 'draft')
                        ->exists()))) {
                throw ValidationException::withMessages([
                    'event' => __('Pool scoring rules cannot change after the first response.'),
                ]);
            }
        });
    }
}
