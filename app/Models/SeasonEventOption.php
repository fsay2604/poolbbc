<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class SeasonEventOption extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonEventOptionFactory> */
    use HasFactory;

    protected $fillable = ['season_event_id', 'houseguest_id', 'label', 'value', 'position', 'is_none'];

    protected function casts(): array
    {
        return ['is_none' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SeasonEvent::class, 'season_event_id');
    }

    public function houseguest(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class);
    }

    public function results(): BelongsToMany
    {
        return $this->belongsToMany(SeasonEventResult::class, 'season_event_result_selections');
    }

    protected static function booted(): void
    {
        $rejectWhenFrozen = function (self $option): void {
            $eventIds = collect([$option->season_event_id, $option->getRawOriginal('season_event_id')])
                ->filter()
                ->unique()
                ->all();

            if (SeasonEvent::query()->whereKey($eventIds)->where(fn ($query) => $query
                ->whereNotNull('options_locked_at')
                ->orWhere('status', '!=', 'draft'))
                ->exists()
                || SeasonEvent::query()->whereKey($eventIds)->where(fn ($query) => $query
                    ->whereHas('poolEvents.predictions')
                    ->orWhereHas('results'))
                    ->exists()) {
                throw ValidationException::withMessages([
                    'option' => __('Official options are frozen after opening or the first response.'),
                ]);
            }
        };

        static::creating($rejectWhenFrozen);
        static::updating($rejectWhenFrozen);
        static::deleting($rejectWhenFrozen);
    }
}
