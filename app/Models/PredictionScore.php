<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionScore extends Model
{
    /** @use HasFactory<\Database\Factories\PredictionScoreFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'prediction_id',
        'week_id',
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

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
