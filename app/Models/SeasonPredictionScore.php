<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonPredictionScore extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonPredictionScoreFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'season_prediction_id',
        'season_id',
        'user_id',
        'points',
        'breakdown',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function seasonPrediction(): BelongsTo
    {
        return $this->belongsTo(SeasonPrediction::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
