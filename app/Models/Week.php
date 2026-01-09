<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Week extends Model
{
    /** @use HasFactory<\Database\Factories\WeekFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'season_id',
        'number',
        'boss_count',
        'nominee_count',
        'evicted_count',
        'name',
        'prediction_deadline_at',
        'is_locked',
        'auto_lock_at',
        'locked_at',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'boss_count' => 'integer',
            'nominee_count' => 'integer',
            'evicted_count' => 'integer',
            'prediction_deadline_at' => 'datetime',
            'is_locked' => 'boolean',
            'auto_lock_at' => 'datetime',
            'locked_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(WeekOutcome::class);
    }

    public function isLocked(?Carbon $now = null): bool
    {
        $now ??= now();

        if ($this->is_locked) {
            return true;
        }

        if ($this->auto_lock_at !== null) {
            return $this->auto_lock_at->lessThanOrEqualTo($now);
        }

        return $now->greaterThanOrEqualTo($this->prediction_deadline_at);
    }

    public function scopeForActiveSeason(Builder $query): Builder
    {
        return $query->whereHas('season', fn (Builder $q) => $q->where('is_active', true));
    }
}
