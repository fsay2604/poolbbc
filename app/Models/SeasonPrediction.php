<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SeasonPrediction extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonPredictionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'season_id',
        'user_id',
        'winner_houseguest_id',
        'first_evicted_houseguest_id',
        'top_6_houseguest_ids',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'top_6_houseguest_ids' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'winner_houseguest_id');
    }

    public function firstEvicted(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'first_evicted_houseguest_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function confirm(?Carbon $now = null): void
    {
        $now ??= now();

        $this->confirmed_at = $now;
    }
}
