<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Prediction extends Model
{
    /** @use HasFactory<\Database\Factories\PredictionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'week_id',
        'user_id',
        'hoh_houseguest_id',
        'nominee_1_houseguest_id',
        'nominee_2_houseguest_id',
        'veto_winner_houseguest_id',
        'veto_used',
        'saved_houseguest_id',
        'replacement_nominee_houseguest_id',
        'evicted_houseguest_id',
        'confirmed_at',
        'last_admin_edited_by_user_id',
        'last_admin_edited_at',
        'admin_edit_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'veto_used' => 'boolean',
            'confirmed_at' => 'datetime',
            'last_admin_edited_at' => 'datetime',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hoh(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'hoh_houseguest_id');
    }

    public function nominee1(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'nominee_1_houseguest_id');
    }

    public function nominee2(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'nominee_2_houseguest_id');
    }

    public function vetoWinner(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'veto_winner_houseguest_id');
    }

    public function savedHouseguest(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'saved_houseguest_id');
    }

    public function replacementNominee(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'replacement_nominee_houseguest_id');
    }

    public function evicted(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class, 'evicted_houseguest_id');
    }

    public function score(): HasOne
    {
        return $this->hasOne(PredictionScore::class);
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
