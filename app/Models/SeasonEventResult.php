<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SeasonEventResult extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonEventResultFactory> */
    use HasFactory;

    protected $fillable = [
        'season_event_id', 'created_by', 'supersedes_id', 'version', 'status',
        'correction_reason', 'published_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $result): void {
            $originalStatus = (string) $result->getRawOriginal('status');
            $newStatus = (string) $result->status;
            $allowedTransitions = [
                'draft' => ['pending', 'published'],
                'pending' => ['failed', 'published'],
                'failed' => ['pending'],
            ];
            $dirtyAttributes = array_keys($result->getDirty());
            $allowedAttributes = ['status', 'published_at', 'updated_at'];
            $isAllowedTransition = in_array($newStatus, $allowedTransitions[$originalStatus] ?? [], true)
                && array_diff($dirtyAttributes, $allowedAttributes) === []
                && ($newStatus !== 'published' || $result->published_at !== null)
                && ($newStatus === 'published' || $result->published_at === null);
            $isDraftAmendment = $originalStatus === 'draft'
                && $newStatus === 'draft'
                && $result->published_at === null
                && array_diff($dirtyAttributes, ['correction_reason', 'updated_at']) === [];

            if (! $isAllowedTransition && ! $isDraftAmendment) {
                throw new LogicException('Official result history is immutable; create a correction version instead.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Official result history cannot be removed.');
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SeasonEvent::class, 'season_event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(SeasonEventOption::class, 'season_event_result_selections')->withTimestamps();
    }

    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }

    public function scoringReceipts(): HasMany
    {
        return $this->hasMany(SeasonEventResultScoringReceipt::class);
    }
}
