<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    protected static function booted(): void
    {
        $rejectReadOnlyPoolMutation = function (self $option): void {
            $eventIds = collect([$option->event_id, $option->getRawOriginal('event_id')])
                ->filter()
                ->unique()
                ->all();
            $poolIds = Event::query()
                ->whereKey($eventIds)
                ->pluck('pool_id')
                ->map(fn ($poolId): int => (int) $poolId)
                ->unique()
                ->values()
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        };

        $rejectWhenFrozen = function (self $option): void {
            $eventIds = collect([$option->event_id, $option->getRawOriginal('event_id')])
                ->filter()
                ->unique()
                ->all();

            if (Event::query()->whereKey($eventIds)->where(fn ($query) => $query
                ->where('status', '!=', 'draft')
                ->orWhereHas('predictions')
                ->orWhereHas('results'))
                ->exists()) {
                throw ValidationException::withMessages([
                    'event' => __('Event options cannot be changed after the first response.'),
                ]);
            }
        };

        static::creating($rejectReadOnlyPoolMutation);
        static::updating($rejectReadOnlyPoolMutation);
        static::deleting($rejectReadOnlyPoolMutation);
        static::creating($rejectWhenFrozen);
        static::updating($rejectWhenFrozen);
        static::deleting($rejectWhenFrozen);
    }
}
