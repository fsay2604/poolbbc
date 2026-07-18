<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EventResult extends Model
{
    /** @use HasFactory<\Database\Factories\EventResultFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id', 'created_by', 'supersedes_id', 'version', 'status', 'correction_reason', 'published_at',
    ];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::creating(function (self $result): void {
            $poolIds = Event::query()
                ->whereKey($result->event_id)
                ->pluck('pool_id')
                ->map(fn ($poolId): int => (int) $poolId)
                ->all();

            Pool::assertAcceptsMutations(...$poolIds);
        });

        static::updating(function (self $result): void {
            $dirtyAttributes = array_keys($result->getDirty());
            $allowedAttributes = ['status', 'published_at', 'updated_at'];
            $isPublication = $result->getRawOriginal('status') === 'draft'
                && $result->status === 'published'
                && $result->published_at !== null
                && array_diff($dirtyAttributes, $allowedAttributes) === [];
            $isDraftAmendment = $result->getRawOriginal('status') === 'draft'
                && $result->status === 'draft'
                && $result->published_at === null
                && array_diff($dirtyAttributes, ['correction_reason', 'updated_at']) === [];

            if (! $isPublication && ! $isDraftAmendment) {
                throw new LogicException('Published event results are immutable; create a correction version instead.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Event result history cannot be removed.');
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function selectionRows(): HasMany
    {
        return $this->hasMany(EventResultSelection::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(EventOption::class, 'event_result_selections')->withTimestamps();
    }

    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }
}
