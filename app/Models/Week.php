<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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
        'name',
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

    public function phases(): HasMany
    {
        return $this->hasMany(WeekPhase::class)->orderBy('position');
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

        return false;
    }

    public function scopeForActiveSeason(Builder $query): Builder
    {
        return $query->whereHas('season', fn (Builder $q) => $q->where('is_active', true));
    }

    protected static function booted(): void
    {
        static::saving(function (self $week): void {
            foreach (['boss_count', 'nominee_count', 'evicted_count'] as $column) {
                if (Schema::hasColumn($week->getTable(), $column)) {
                    continue;
                }

                if (array_key_exists($column, $week->getAttributes())) {
                    unset($week->{$column});
                }
            }
        });
    }
}
