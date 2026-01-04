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
        'name',
        'prediction_deadline_at',
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
            'prediction_deadline_at' => 'datetime',
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

        return $this->locked_at !== null || $now->greaterThanOrEqualTo($this->prediction_deadline_at);
    }

    public function scopeForActiveSeason(Builder $query): Builder
    {
        return $query->whereHas('season', fn (Builder $q) => $q->where('is_active', true));
    }
}
