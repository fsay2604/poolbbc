<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Season extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'starts_on',
        'ends_on',
        'prediction_opens_at',
        'prediction_locks_at',
        'winner_houseguest_id',
        'first_evicted_houseguest_id',
        'top_6_houseguest_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'prediction_opens_at' => 'datetime',
            'prediction_locks_at' => 'datetime',
            'top_6_houseguest_ids' => 'array',
        ];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(Week::class);
    }

    public function houseguests(): HasMany
    {
        return $this->hasMany(Houseguest::class);
    }

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function canonicalRounds(): HasMany
    {
        return $this->hasMany(SeasonRound::class)->orderBy('position');
    }

    public function seasonPredictions(): HasMany
    {
        return $this->hasMany(SeasonPrediction::class);
    }

    public function predictionsAreOpen(?Carbon $now = null): bool
    {
        $now ??= now();

        return ($this->prediction_opens_at === null || $this->prediction_opens_at->lessThanOrEqualTo($now))
            && ! $this->predictionsAreLocked($now);
    }

    public function predictionsAreLocked(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->prediction_locks_at !== null
            && $this->prediction_locks_at->lessThanOrEqualTo($now);
    }
}
