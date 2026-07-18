<?php

namespace App\Models;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    /** @use HasFactory<\Database\Factories\EventTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'name', 'slug', 'is_standard', 'default_mode', 'answer_source', 'default_config',
    ];

    protected $attributes = [
        'is_standard' => false,
        'default_mode' => EventMode::Prediction->value,
        'answer_source' => AnswerSource::Houseguests->value,
    ];

    protected function casts(): array
    {
        return [
            'is_standard' => 'boolean',
            'default_mode' => EventMode::class,
            'answer_source' => AnswerSource::class,
            'default_config' => 'array',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function seasonEvents(): HasMany
    {
        return $this->hasMany(SeasonEvent::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $eventType): void {
            $poolIds = collect([$eventType->pool_id, $eventType->getRawOriginal('pool_id')])
                ->filter()
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
            $eventType->scope_key = $eventType->pool_id === null ? 'global' : 'pool:'.$eventType->pool_id;
        });

        static::deleting(function (self $eventType): void {
            $poolIds = collect([$eventType->pool_id, $eventType->getRawOriginal('pool_id')])
                ->filter()
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        });
    }
}
