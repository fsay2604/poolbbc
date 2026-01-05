<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekOutcome extends Model
{
    /** @use HasFactory<\Database\Factories\WeekOutcomeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'week_id',
        'hoh_houseguest_id',
        'boss_houseguest_ids',
        'nominee_1_houseguest_id',
        'nominee_2_houseguest_id',
        'nominee_houseguest_ids',
        'veto_winner_houseguest_id',
        'veto_used',
        'saved_houseguest_id',
        'replacement_nominee_houseguest_id',
        'evicted_houseguest_id',
        'evicted_houseguest_ids',
        'last_admin_edited_by_user_id',
        'last_admin_edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'veto_used' => 'boolean',
            'boss_houseguest_ids' => 'array',
            'nominee_houseguest_ids' => 'array',
            'evicted_houseguest_ids' => 'array',
            'last_admin_edited_at' => 'datetime',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
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
}
